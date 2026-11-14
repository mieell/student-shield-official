"""Offline unit and integration tests for Gemini Safe Space clinical triage."""

import importlib.util
import json
import os
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import torch

SERVICE_DIR = Path(__file__).resolve().parents[1]
if str(SERVICE_DIR) not in sys.path:
    sys.path.insert(0, str(SERVICE_DIR))

import gemini_triage  # noqa: E402


class _FakeResponse:
    def __init__(self, text):
        self.text = text


class GeminiTriageUnitTests(unittest.TestCase):
    def setUp(self) -> None:
        self._saved_env = {
            key: os.environ.get(key)
            for key in ("GEMINI_API_KEY", "GEMINI_ENABLED", "GEMINI_MODEL", "GEMINI_TIMEOUT_SECONDS")
        }
        os.environ.pop("GEMINI_API_KEY", None)
        os.environ.pop("GEMINI_ENABLED", None)
        os.environ.pop("GEMINI_MODEL", None)
        os.environ.pop("GEMINI_TIMEOUT_SECONDS", None)

    def tearDown(self) -> None:
        for key, value in self._saved_env.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value

    def test_disabled_when_api_key_missing_or_flag_false(self) -> None:
        calls = []

        class _Client:
            class models:
                @staticmethod
                def generate_content(**kwargs):
                    calls.append(kwargs)
                    return _FakeResponse("{}")

        self.assertFalse(gemini_triage.is_gemini_enabled())
        self.assertIsNone(gemini_triage.evaluate_vent_with_gemini("stressed today", client=_Client()))
        self.assertEqual(calls, [])

        os.environ["GEMINI_API_KEY"] = "test-key"
        os.environ["GEMINI_ENABLED"] = "false"
        self.assertFalse(gemini_triage.is_gemini_enabled())
        self.assertIsNone(gemini_triage.evaluate_vent_with_gemini("stressed today", client=_Client()))
        self.assertEqual(calls, [])

    def test_prompt_excludes_pii_and_parses_structured_json(self) -> None:
        os.environ["GEMINI_API_KEY"] = "test-key"
        os.environ["GEMINI_MODEL"] = "gemini-3-flash-preview"
        captured = {}

        class _Client:
            class models:
                @staticmethod
                def generate_content(**kwargs):
                    captured.update(kwargs)
                    return _FakeResponse(
                        json.dumps(
                            {
                                "risk_level": "HIGH",
                                "risk_score": 82,
                                "sarcasm_or_hyperbole": False,
                                "taglish_nuance": "Veiled Filipino hopelessness idiom",
                                "counselor_reasoning": "Student expresses exhaustion with continuing daily life.",
                            }
                        )
                    )

        fake_genai = SimpleNamespace(
            Client=lambda **kw: _Client(),
            types=SimpleNamespace(
                HttpOptions=lambda **kw: kw,
                GenerateContentConfig=lambda **kw: kw,
            ),
        )

        result = gemini_triage.evaluate_vent_with_gemini(
            "pagod na pagod na ako mabuhay sa lahat ng nangyayari",
            genai_module=fake_genai,
        )
        self.assertIsNotNone(result)
        self.assertEqual(result["risk_level"], "HIGH")
        self.assertEqual(result["risk_score"], 82)
        self.assertEqual(captured["model"], "gemini-3-flash-preview")
        self.assertIn("<student_vent>", captured["contents"])
        self.assertNotIn("student_id", captured["contents"])
        self.assertNotIn("risk_history", captured["contents"])

    def test_parse_payload_clamps_bands_and_rejects_malformed_json(self) -> None:
        self.assertIsNone(gemini_triage.parse_gemini_payload("not-json"))
        self.assertIsNone(gemini_triage.parse_gemini_payload(json.dumps({"risk_level": "EXTREME"})))
        self.assertIsNone(
            gemini_triage.parse_gemini_payload(
                json.dumps({"risk_level": "LOW", "risk_score": 15, "counselor_reasoning": ""})
            )
        )

        clamped = gemini_triage.parse_gemini_payload(
            "```json\n"
            + json.dumps(
                {
                    "risk_level": "CRITICAL",
                    "risk_score": 12,
                    "sarcasm_or_hyperbole": False,
                    "taglish_nuance": "",
                    "counselor_reasoning": "Immediate safety review needed.",
                }
            )
            + "\n```"
        )
        self.assertIsNotNone(clamped)
        self.assertEqual(clamped["risk_level"], "CRITICAL")
        self.assertEqual(clamped["risk_score"], 86)

        # C-SSRS Level 2 passive ideation must map to HIGH (>= 79), never inflate to CRITICAL (no parent alert).
        passive_pinned = gemini_triage.parse_gemini_payload(
            json.dumps(
                {
                    "triage_category": "passive_ideation_cssrs2",
                    "risk_level": "CRITICAL",
                    "risk_score": 92,
                    "sarcasm_or_hyperbole": False,
                    "taglish_nuance": "Passive disappearance ideation",
                    "counselor_reasoning": "C-SSRS Level 2 counselor check-in required.",
                }
            )
        )
        self.assertIsNotNone(passive_pinned)
        self.assertEqual(passive_pinned["risk_level"], "HIGH")
        self.assertGreaterEqual(passive_pinned["risk_score"], 79)
        self.assertLessEqual(passive_pinned["risk_score"], 85)

    def test_timeout_and_rate_limit_errors_fail_cleanly(self) -> None:
        os.environ["GEMINI_API_KEY"] = "test-key"
        os.environ["GEMINI_TIMEOUT_SECONDS"] = "1.0"

        class _ErrorClient:
            class models:
                @staticmethod
                def generate_content(**kwargs):
                    raise RuntimeError("429 RESOURCE_EXHAUSTED")

        self.assertIsNone(
            gemini_triage.evaluate_vent_with_gemini(
                "feeling stressed",
                client=_ErrorClient(),
                genai_module=SimpleNamespace(types=None),
            )
        )

        with patch.object(gemini_triage, "_invoke_gemini", side_effect=gemini_triage.FuturesTimeoutError()):
            self.assertIsNone(gemini_triage.evaluate_vent_with_gemini("feeling stressed"))

    def test_merge_never_downgrades_crisis_or_history_floors(self) -> None:
        benign_gem = {
            "risk_level": "LOW",
            "risk_score": 15,
            "sarcasm_or_hyperbole": True,
            "taglish_nuance": "",
            "counselor_reasoning": "Appears sarcastic.",
        }

        for lock_kwargs in (
            {"flags": {"cat1": True}},
            {"flags": {"passive_ideation": True}},
            {"flags": {"cat2": True}},
            {"flags": {"final": True}},
            {"flags": {"sexual": True}},
            {"violent_threat": True},
            {"history_escalated": True},
            {"base_score": 0.70},
        ):
            risk, percent, reason = gemini_triage.merge_gemini_assessment(
                "CRITICAL" if lock_kwargs.get("flags", {}).get("cat1") else "HIGH",
                90 if lock_kwargs.get("flags", {}).get("cat1") else 79,
                "Baseline warning.",
                benign_gem,
                **lock_kwargs,
            )
            self.assertIn(risk, ("HIGH", "CRITICAL"))
            self.assertGreaterEqual(percent, 79)
            self.assertIn("Deterministic safety floor retained.", reason)

    def test_merge_escalates_subtle_distress_and_clarifies_mild_hyperbole(self) -> None:
        subtle_gem = {
            "risk_level": "CRITICAL",
            "risk_score": 91,
            "sarcasm_or_hyperbole": False,
            "taglish_nuance": "Bisaya passive disappearance expression",
            "counselor_reasoning": "Student signals readiness to vanish without explicit English keywords.",
        }
        risk, percent, reason = gemini_triage.merge_gemini_assessment(
            "LOW",
            20,
            "Minimal baseline distress.",
            subtle_gem,
        )
        self.assertEqual(risk, "CRITICAL")
        self.assertEqual(percent, 91)
        self.assertIn("Gemini Clinical Escalation (LOW -> CRITICAL)", reason)
        self.assertIn("Bisaya passive disappearance expression", reason)

        hyperbole_gem = {
            "risk_level": "LOW",
            "risk_score": 18,
            "sarcasm_or_hyperbole": True,
            "taglish_nuance": "Common Filipino student exam hyperbole",
            "counselor_reasoning": "Exaggerated frustration over difficult quiz without safety intent.",
        }
        risk, percent, reason = gemini_triage.merge_gemini_assessment(
            "MEDIUM",
            48,
            "Academic pressure detected.",
            hyperbole_gem,
            flags={"academic": True},
            base_score=0.25,
            signal_count=1,
        )
        self.assertEqual(risk, "LOW")
        self.assertEqual(percent, 18)
        self.assertIn("Gemini Contextual Clarification (MEDIUM -> LOW)", reason)

    def test_main_analyze_text_sync_integration_respects_floor_and_escalation(self) -> None:
        classifier = SimpleNamespace(
            tokenizer=lambda *args, **kwargs: {"attention_mask": torch.ones((1, 1))},
            model=SimpleNamespace(
                base_model=lambda **kwargs: SimpleNamespace(
                    last_hidden_state=torch.zeros((1, 1, 768))
                )
            ),
        )
        fake_transformers = SimpleNamespace(pipeline=lambda *args, **kwargs: classifier)
        fake_langid_inner = SimpleNamespace(
            model="stub-model",
            LanguageIdentifier=SimpleNamespace(
                from_modelstring=lambda *args, **kwargs: SimpleNamespace(
                    classify=lambda text: ("tl", 0.99)
                )
            ),
        )
        fake_langid = SimpleNamespace(langid=fake_langid_inner)

        with (
            patch.dict(
                sys.modules,
                {
                    "transformers": fake_transformers,
                    "langid": fake_langid,
                    "langid.langid": fake_langid_inner,
                },
            ),
            patch("nltk.data.find", return_value=True),
            patch("deep_translator.GoogleTranslator.translate", return_value="I feel overwhelmed."),
        ):
            spec = importlib.util.spec_from_file_location("main_under_gemini_test", SERVICE_DIR / "main.py")
            main_mod = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(main_mod)

        with patch.object(main_mod, "get_risk_components", return_value=(0.0, 0.0)):
            with patch.object(
                main_mod,
                "evaluate_vent_with_gemini",
                return_value={
                    "risk_level": "HIGH",
                    "risk_score": 78,
                    "sarcasm_or_hyperbole": False,
                    "taglish_nuance": "Bisaya hopelessness idiom",
                    "counselor_reasoning": "Veiled hopelessness requiring counselor check-in.",
                },
            ) as mock_gem:
                res = main_mod.analyze_text_sync(
                    "kapoy na kaayo diria murag wala nay pulos tanan",
                    student_id="SECRET-STUDENT-42",
                )
                self.assertEqual(res["risk_level"], "HIGH")
                self.assertEqual(res["risk_score"], 78.0)
                self.assertIn("Veiled hopelessness requiring counselor check-in.", res["flag_reason"])
                self.assertEqual(mock_gem.call_count, 1)
                self.assertEqual(len(mock_gem.call_args.args), 1)
                self.assertNotIn("SECRET-STUDENT-42", mock_gem.call_args.args[0])

            # Deterministic cat1 floor must ignore Gemini attempts to downgrade.
            with patch.object(
                main_mod,
                "evaluate_vent_with_gemini",
                return_value={
                    "risk_level": "LOW",
                    "risk_score": 12,
                    "sarcasm_or_hyperbole": True,
                    "taglish_nuance": "",
                    "counselor_reasoning": "Claimed banter.",
                },
            ):
                crisis_res = main_mod.analyze_text_sync("I want to kill myself tonight")
                self.assertEqual(crisis_res["risk_level"], "CRITICAL")
                self.assertGreaterEqual(crisis_res["risk_score"], 86.0)
                self.assertIn("Deterministic safety floor retained.", crisis_res["flag_reason"])


if __name__ == "__main__":
    unittest.main()
