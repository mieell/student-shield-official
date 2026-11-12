"""Exercise the complete risk rules offline, with translation and model scores mocked."""

import importlib.util
import sys
from pathlib import Path
from types import SimpleNamespace

import pytest
import torch


@pytest.fixture
def analyzer(monkeypatch):
    service_dir = Path(__file__).resolve().parents[1]
    monkeypatch.syspath_prepend(str(service_dir))
    classifier = SimpleNamespace(
        tokenizer=lambda *args, **kwargs: {"attention_mask": torch.ones((1, 1))},
        model=SimpleNamespace(
            base_model=lambda **kwargs: SimpleNamespace(
                last_hidden_state=torch.zeros((1, 1, 768))
            )
        ),
    )
    # Stub the lazy Transformers module itself so import-time model loading
    # cannot make a network request, even on the first import.
    monkeypatch.setitem(sys.modules, "transformers", SimpleNamespace(
        pipeline=lambda *args, **kwargs: classifier
    ))
    monkeypatch.setattr("nltk.data.find", lambda *args, **kwargs: True)
    # Deliberately omit the crisis wording from the translation: the raw Tagalog
    # must be enough to trigger the safety rules despite a low model score.
    monkeypatch.setattr("deep_translator.GoogleTranslator.translate", lambda *args: "I am sad.")
    spec = importlib.util.spec_from_file_location("risk_rules_under_test", service_dir / "main.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    monkeypatch.setattr(module, "get_risk_components", lambda *args: (0.0, 0.0))
    return module.analyze_text_sync


@pytest.mark.parametrize("text", [
    "sinasaksak ko sarili ko sa sobrang lungkot. like naglalaslas",
    "naglalaslas ako",
    "maglalaslas ako haha charot",
    "naglaslas ako",
    "maglaslas",
    "nilalaslas ko ang braso ko",
    "nilaslas ko ang pulso ko",
    "laslasin ko ang pulso ko",
    "sinasaktan ko sarili ko",
    "sinaktan ko ang sarili ko",
    "sasaktan ang sarili ko",
    "saktan ko ang sarili",
    "sinasaksak ko sarili ko",
    "sinaksak ang sarili ko",
    "sasaksakin ko ang sarili ko",
    "saksakin ko ang sarili",
    "SINASAKTAN KO\nANG SARILI KO lol",
    "kill myself",
    "magpakamatay",
])
def test_explicit_self_harm_is_critical_despite_low_scores(analyzer, text):
    result = analyzer(text)
    assert result["risk_level"] == "CRITICAL"
    assert 86 <= result["risk_score"] <= 100


@pytest.mark.parametrize("text", [
    "sinasaktan ako ng kaklase ko",
    "sinaksak ang kaklase ko",
    "sinasaktan ang damdamin ko",
    "inaalagaan ko ang sarili ko",
    "nalulungkot ako",
    "Naglalaslaser ang laruan",
])
def test_other_directed_harm_and_ordinary_sadness_are_not_critical(analyzer, text):
    assert analyzer(text)["risk_level"] != "CRITICAL"


@pytest.mark.parametrize("component", [0.0, 0.28, 0.60])
def test_mixed_distress_is_not_erased_by_positive_mentions(analyzer, monkeypatch, component):
    text = (Path(__file__).parent / "fixtures" / "mixed_distress.txt").read_text(encoding="utf-8")
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (component, component))
    result = analyzer(text)
    assert result["risk_level"] in {"MEDIUM", "HIGH"}
    assert "Positive/grateful" not in result["flag_reason"]


@pytest.mark.parametrize("text", [
    "I've been tired for so long. I'm grateful my friends care about me.",
    "I have been tired for months, even though I am excited for my friends.",
    "I'm just pretending to keep up. My classmates are excited about the future.",
    "I feel like nobody really knows me. I'm grateful for my family.",
])
def test_indirect_distress_survives_positive_context(analyzer, text):
    assert analyzer(text)["risk_level"] in {"MEDIUM", "HIGH"}


@pytest.mark.parametrize("text", [
    "I am grateful for my friends and excited about our plans.",
    "I am tired after football practice, but grateful for a great afternoon.",
    "I haven't been tired for months. I'm excited about my future.",
    "I'm not pretending to keep up. I'm grateful for my friends.",
    "I used to be tired, but I feel good now and I'm grateful.",
    "Masaya ako at sobrang thankful sa mga kaibigan ko.",
])
def test_positive_and_ordinary_fatigue_entries_stay_low(analyzer, text):
    assert analyzer(text)["risk_level"] == "LOW"


def test_positive_words_do_not_override_strong_neural_distress(analyzer, monkeypatch):
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.90, 0.90))
    result = analyzer("I'm grateful for the people in my life.")
    assert result["risk_level"] == "HIGH"


@pytest.mark.parametrize("text", [
    "I just wanna talk to someone because I really feel down and sad",
    "Can I talk to someone? I'm sad and lonely",
    "nalulungkot ako",
])
def test_ordinary_sadness_stays_medium_with_high_neural_scores(analyzer, monkeypatch, text):
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.90, 0.95))
    assert analyzer(text)["risk_level"] == "MEDIUM"


@pytest.mark.parametrize("text", [
    "Every single day feels like I am carrying an unbearable weight, and I have no strength left to keep fighting. I am completely exhausted from pretending to be okay when it feels like my life has no direction or purpose anymore.",
    "Sobrang drained na ako mentally at emotionally, to the point na hindi ko na makita ang sarili ko sa future. Parang araw-araw na lang akong nalulunod sa lungkot at gusto ko nang sumuko dahil wala namang nagbabago.",
])
def test_severe_hopelessness_and_futurelessness_classify_as_high(analyzer, text):
    assert analyzer(text)["risk_level"] == "HIGH"
