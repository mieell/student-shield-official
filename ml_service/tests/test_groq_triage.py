"""Offline unit and integration tests for Groq Safe Space clinical triage."""

import importlib.util
import json
import os
import sys
import unittest
import urllib.error
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import torch

SERVICE_DIR = Path(__file__).resolve().parents[1]
if str(SERVICE_DIR) not in sys.path:
    sys.path.insert(0, str(SERVICE_DIR))

import groq_triage  # noqa: E402


class GroqTriageUnitTests(unittest.TestCase):
    def setUp(self) -> None:
        self._saved_env = {
            key: os.environ.get(key)
            for key in ("GROQ_API_KEY", "GROQ_ENABLED", "GROQ_MODEL", "GROQ_TIMEOUT_SECONDS")
        }
        os.environ.pop("GROQ_API_KEY", None)
        os.environ.pop("GROQ_ENABLED", None)
        os.environ.pop("GROQ_MODEL", None)
        os.environ.pop("GROQ_TIMEOUT_SECONDS", None)

    def tearDown(self) -> None:
        for key, value in self._saved_env.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value

    def test_disabled_when_api_key_missing_or_flag_false(self) -> None:
        calls = []

        def _fake_post(url, body, api_key, timeout_s):
            calls.append((url, body, api_key, timeout_s))
            return "{}"

        self.assertFalse(groq_triage.is_groq_enabled())
        self.assertIsNone(groq_triage.evaluate_vent_with_groq("stressed today", http_post=_fake_post))
        self.assertEqual(calls, [])

        os.environ["GROQ_API_KEY"] = "gsk_test_key"
        os.environ["GROQ_ENABLED"] = "false"
        self.assertFalse(groq_triage.is_groq_enabled())
        self.assertIsNone(groq_triage.evaluate_vent_with_groq("stressed today", http_post=_fake_post))
        self.assertEqual(calls, [])

    def test_prompt_excludes_pii_and_parses_chat_completions_json(self) -> None:
        os.environ["GROQ_API_KEY"] = ' "gsk_test_key" '
        os.environ["GROQ_MODEL"] = "openai/gpt-oss-120b"
        captured = {}

        def _fake_post(url, body, api_key, timeout_s):
            captured["url"] = url
            captured["body"] = body
            captured["api_key"] = api_key
            captured["timeout_s"] = timeout_s
            return json.dumps(
                {
                    "choices": [
                        {
                            "message": {
                                "content": json.dumps(
                                    {
                                        "triage_category": "passive_ideation_cssrs2",
                                        "risk_level": "HIGH",
                                        "risk_score": 82,
                                        "sarcasm_or_hyperbole": False,
                                        "taglish_nuance": "Veiled Filipino hopelessness idiom",
                                        "counselor_reasoning": "Student expresses exhaustion with continuing daily life.",
                                    }
                                )
                            }
                        }
                    ]
                }
            )

        result = groq_triage.evaluate_vent_with_groq(
            "pagod na pagod na ako mabuhay sa lahat ng nangyayari",
            http_post=_fake_post,
        )
        self.assertIsNotNone(result)
        self.assertEqual(result["risk_level"], "HIGH")
        self.assertEqual(result["risk_score"], 82)
        self.assertEqual(captured["url"], "https://api.groq.com/openai/v1/chat/completions")
        self.assertEqual(captured["api_key"], "gsk_test_key")
        self.assertEqual(captured["body"]["model"], "openai/gpt-oss-120b")
        self.assertEqual(captured["body"]["max_completion_tokens"], 1200)
        self.assertEqual(captured["body"]["reasoning_effort"], "medium")
        self.assertEqual(captured["body"]["response_format"], {"type": "json_object"})
        user_msg = captured["body"]["messages"][1]["content"]
        sys_msg = captured["body"]["messages"][0]["content"]
        self.assertIn("<student_vent>", user_msg)
        self.assertNotIn("student_id", user_msg)
        self.assertNotIn("risk_history", user_msg)
        self.assertIn("DepEd Child Protection Policy DO 40 s.2012", sys_msg)
        self.assertIn("patay ako kay mama", sys_msg)
        self.assertIn("healed past NSSI in recovery", sys_msg)
        self.assertIn("perceived burdensomeness", sys_msg)

        # Vendor/provider names must be stripped if accidentally echoed in clinical text.
        leaky_payload = groq_triage.parse_groq_payload(
            json.dumps(
                {
                    "triage_category": "positive_or_banter",
                    "risk_level": "LOW",
                    "risk_score": 14,
                    "sarcasm_or_hyperbole": True,
                    "taglish_nuance": "Groq LLM idiom note",
                    "counselor_reasoning": "Groq and OpenAI model detected ordinary school banter.",
                }
            )
        )
        self.assertIsNotNone(leaky_payload)
        self.assertNotIn("groq", leaky_payload["counselor_reasoning"].lower())
        self.assertNotIn("openai", leaky_payload["counselor_reasoning"].lower())
        self.assertNotIn("groq", leaky_payload["taglish_nuance"].lower())
        self.assertNotIn("llm", leaky_payload["taglish_nuance"].lower())

    def test_parse_payload_clamps_bands_and_rejects_malformed_json(self) -> None:
        self.assertIsNone(groq_triage.parse_groq_payload("not-json"))
        self.assertIsNone(groq_triage.parse_groq_payload(json.dumps({"risk_level": "EXTREME"})))
        self.assertIsNone(
            groq_triage.parse_groq_payload(
                json.dumps({"risk_level": "LOW", "risk_score": 15, "counselor_reasoning": ""})
            )
        )

        clamped = groq_triage.parse_groq_payload(
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
        passive_pinned = groq_triage.parse_groq_payload(
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
        os.environ["GROQ_API_KEY"] = "gsk_test_key"
        os.environ["GROQ_TIMEOUT_SECONDS"] = "1.0"

        def _rate_limited(*args, **kwargs):
            raise urllib.error.HTTPError(
                "https://api.groq.com/openai/v1/chat/completions", 429, "Too Many Requests", {}, None
            )

        self.assertIsNone(groq_triage.evaluate_vent_with_groq("feeling stressed", http_post=_rate_limited))
        self.assertEqual(groq_triage.get_last_groq_status(), "http_429")

        def _timed_out(*args, **kwargs):
            raise TimeoutError("timed out")

        self.assertIsNone(groq_triage.evaluate_vent_with_groq("feeling stressed", http_post=_timed_out))
        self.assertEqual(groq_triage.get_last_groq_status(), "timeout_1.0s")

        attempted_models = []

        def _not_found_then_ok(url, body, api_key, timeout_s):
            attempted_models.append(body["model"])
            if len(attempted_models) == 1:
                raise urllib.error.HTTPError(url, 404, "Not Found", {}, None)
            return json.dumps({
                "choices": [{
                    "message": {
                        "content": json.dumps({
                            "triage_category": "mild_or_single_distress",
                            "risk_level": "MEDIUM",
                            "risk_score": 45,
                            "sarcasm_or_hyperbole": False,
                            "taglish_nuance": "",
                            "counselor_reasoning": "Fallback model resolved deprecated primary model.",
                        })
                    }
                }]
            })

        os.environ["GROQ_MODEL"] = "retired-model-id"
        fallback_res = groq_triage.evaluate_vent_with_groq("stressed with modules", http_post=_not_found_then_ok)
        self.assertIsNotNone(fallback_res)
        self.assertEqual(attempted_models, ["retired-model-id", "openai/gpt-oss-120b"])
        self.assertEqual(groq_triage.get_last_groq_status(), "ok")

    def test_merge_never_downgrades_crisis_or_history_floors(self) -> None:
        benign_groq = {
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
            risk, percent, reason = groq_triage.merge_groq_assessment(
                "CRITICAL" if lock_kwargs.get("flags", {}).get("cat1") else "HIGH",
                90 if lock_kwargs.get("flags", {}).get("cat1") else 79,
                "Baseline warning.",
                benign_groq,
                **lock_kwargs,
            )
            self.assertIn(risk, ("HIGH", "CRITICAL"))
            self.assertGreaterEqual(percent, 79)
            self.assertIn("Deterministic safety floor retained.", reason)

    def test_merge_escalates_subtle_distress_and_clarifies_mild_hyperbole(self) -> None:
        subtle_groq = {
            "risk_level": "CRITICAL",
            "risk_score": 91,
            "sarcasm_or_hyperbole": False,
            "taglish_nuance": "Bisaya passive disappearance expression",
            "counselor_reasoning": "Student signals readiness to vanish without explicit English keywords.",
        }
        risk, percent, reason = groq_triage.merge_groq_assessment(
            "LOW",
            20,
            "Minimal baseline distress.",
            subtle_groq,
        )
        self.assertEqual(risk, "CRITICAL")
        self.assertEqual(percent, 91)
        self.assertIn("Clinical Escalation: LOW -> CRITICAL", reason)
        self.assertIn("Bisaya passive disappearance expression", reason)
        self.assertNotIn("Minimal baseline distress.", reason)
        self.assertNotIn("groq", reason.lower())

        hyperbole_groq = {
            "risk_level": "LOW",
            "risk_score": 18,
            "sarcasm_or_hyperbole": True,
            "taglish_nuance": "Common Filipino student exam hyperbole",
            "counselor_reasoning": "Exaggerated frustration over difficult quiz without safety intent.",
        }
        risk, percent, reason = groq_triage.merge_groq_assessment(
            "MEDIUM",
            48,
            "[Predictive Recurrence Risk: 70%] Academic pressure detected.",
            hyperbole_groq,
            flags={"academic": True},
            base_score=0.25,
            signal_count=1,
        )
        self.assertEqual(risk, "LOW")
        self.assertEqual(percent, 18)
        self.assertIn("[Predictive Recurrence Risk: 70%]", reason)
        self.assertIn("Context Clarification: MEDIUM -> LOW", reason)
        self.assertNotIn("Academic pressure detected.", reason)
        self.assertNotIn("groq", reason.lower())

        # Same-band ML + clinical consensus blends both scores cleanly and omits noisy NLP filler.
        agree_risk, agree_pct, agree_reason = groq_triage.merge_groq_assessment(
            "LOW",
            28,
            "NLP: Positive/grateful vent — no active distress indicators.",
            {
                "triage_category": "positive_or_banter",
                "risk_level": "LOW",
                "risk_score": 10,
                "sarcasm_or_hyperbole": True,
                "taglish_nuance": "lowkey natatakot",
                "counselor_reasoning": "Playful academic worry buffered by active coping; no distress.",
            },
        )
        self.assertEqual(agree_risk, "LOW")
        self.assertEqual(agree_pct, 18)
        self.assertTrue(agree_reason.startswith("[Clinical Review] "))
        self.assertNotIn("NLP: Positive/grateful", agree_reason)
        self.assertNotIn("groq", agree_reason.lower())

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
            spec = importlib.util.spec_from_file_location("main_under_groq_test", SERVICE_DIR / "main.py")
            main_mod = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(main_mod)

        with patch.object(main_mod, "get_risk_components", return_value=(0.0, 0.0)):
            with patch.object(
                main_mod,
                "evaluate_vent_with_groq",
                return_value={
                    "risk_level": "HIGH",
                    "risk_score": 78,
                    "sarcasm_or_hyperbole": False,
                    "taglish_nuance": "Bisaya hopelessness idiom",
                    "counselor_reasoning": "Veiled hopelessness requiring counselor check-in.",
                },
            ) as mock_groq:
                res = main_mod.analyze_text_sync(
                    "kapoy na kaayo diria murag wala nay pulos tanan",
                    student_id="SECRET-STUDENT-42",
                )
                self.assertEqual(res["risk_level"], "HIGH")
                self.assertEqual(res["risk_score"], 78.0)
                self.assertIn("Veiled hopelessness requiring counselor check-in", res["flag_reason"])
                self.assertIn("Bisaya hopelessness idiom", res["flag_reason"])
                self.assertNotIn("groq", res["flag_reason"].lower())
                self.assertEqual(mock_groq.call_count, 1)
                self.assertEqual(len(mock_groq.call_args.args), 1)
                self.assertNotIn("SECRET-STUDENT-42", mock_groq.call_args.args[0])
                ml_ctx = mock_groq.call_args.kwargs.get("ml_context")
                self.assertIsInstance(ml_ctx, dict)
                self.assertIn("ml_risk_level", ml_ctx)
                self.assertIn("distress_confidence", ml_ctx)
                self.assertNotIn("SECRET-STUDENT-42", json.dumps(ml_ctx))

            # Deterministic cat1 floor must ignore Groq attempts to downgrade.
            with patch.object(
                main_mod,
                "evaluate_vent_with_groq",
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
