"""Exercise the complete risk rules offline, with translation and model scores mocked."""

import importlib.util
import json
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
    "pakamatay na ako tangina",
    "papakamatay na ako",
    "magpapakamatay na ako",
    "nagpakamatay",
    "nagbigti",
    "magpatiwakal",
    "i surely do want to kill my self wow amazing lupettt i love it!!",
    "going to cut my self",
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
    "I am so mentally and emotionally drained that I can no longer picture myself having a future. It feels like I am drowning in sadness every single day, and I just want to give up because nothing ever gets better.",
    "Sobrang pagod na ako kakaisip kung kaya ko pa bang tapusin lahat ng requirements na ito, parang kahit anong effort ang ibigay ko ay kulang pa rin. Honestly, drained na drained na ako mentally at pakiramdam ko ay napag-iiwanan na ako ng lahat.",
    "would even notice if I wasn't here anymore",
    "Araw-araw na lang akong gumigising na parang pasan ko ang buong mundo at wala nang natitirang lakas para lumaban pa sa sarili kong isip. Kahit anong pilit kong ngumiti at makisama sa mga tao sa paligid ko, sobrang dilim at empty pa rin ng pakiramdam ko sa loob. Pagod na pagod na akong magpanggap na kaya ko pa, dahil ang totoo ay matagal na akong sumuko at naghihintay na lang na matapos ang lahat. Pakiramdam ko ay wala na akong halaga at pabigat na lang ako sa pamilya ko, kaya minsan iniisip ko na mas mabuti pang maglaho na lang nang tuluyan para matapos na itong walang katapusang sakit at pagod.",
])
def test_severe_hopelessness_and_futurelessness_classify_as_high(analyzer, text):
    assert analyzer(text)["risk_level"] == "HIGH"


@pytest.mark.parametrize("text", [
    "I was literally sobbing on the floor and ready to give up all hope this morning because we thought our family dog was not going to survive his emergency surgery. My chest hurt so badly from crying that I could barely breathe, and I honestly felt like my world was ending—until the vet walked out smiling and told us he pulled through completely fine! I collapsed into my chair ugly-crying and shaking uncontrollably from pure relief. I practically lost my voice screaming with joy in the waiting room, lowkey the greatest miracle of my entire life, no cap!",
    "Sobrang solid ng naging weekend namin kasama ang buong barkada dahil natuloy na rin sa wakas yung matagal naming pinaplanong food trip at movie marathon! Walang katapusang kwentuhan at tawanan lang buong gabi, nakakawala talaga ng pagod kapag kasama mo yung mga taong totoong nagpapasaya sa'yo. Ang sarap sa feeling na lahat kami ay nag-enjoy at nakapag-recharge bago bumalik sa kanya-kanyang trabaho at klase. Sobrang grateful lang talaga ako sa ganitong klase ng friendship at positive energy sa buhay ko, for real for real!",
])
def test_resolved_relief_narrative_and_positive_taglish_stay_low(analyzer, text):
    assert analyzer(text)["risk_level"] == "LOW"


def test_resolved_relief_never_masks_explicit_suicidal_disclosure(analyzer):
    text = (
        "We thought our dog wouldn't survive, until he pulled through completely fine! "
        "Still I honestly felt like ending my life and want to kill my self from pure relief? No, I want to die."
    )
    assert analyzer(text)["risk_level"] == "CRITICAL"


@pytest.mark.parametrize('case', json.loads((Path(__file__).parent / 'fixtures/crisis_context.json').read_text()))
def test_context_scoping_preserves_current_disclosures(analyzer, case):
    result = analyzer(case['text'])
    assert (result['risk_level'] == 'CRITICAL') == case['critical']


def test_passive_disappearance_is_high_not_critical(analyzer):
    result = analyzer('I wish I could disappear. I do not have a plan, but I keep wishing I could stop being here.')
    assert result['risk_level'] == 'HIGH'


@pytest.mark.parametrize("text", [
    "papakamatay na ako at hindi ko na kaya mabuhay ngayon sobrang hirap na ng mga nararamdaman ko parang wala lang ako sa iba",
    "i surely do want to kill my self wow amazing lupettt i love ittt!!",
    "nagbigti na lang kaya ako",
    "gusto ko nang magpatiwakal",
])
def test_tagalog_inflections_and_spaced_kill_myself_are_critical(analyzer, text):
    assert analyzer(text)["risk_level"] == "CRITICAL"


def test_case1_resolved_thesis_panic_stays_low_with_and_without_translation(analyzer, monkeypatch):
    case1 = (
        "Gusto ko nang sumuko at maglaho kanina habang naghihintay ng deliberation ng panel, "
        "kasi akala ko bumagsak na kami at nasayang lahat ng puyat namin. Sobrang bigat sa dibdib at "
        "parang hihimatayin na ako sa sobrang kaba, halos maiyak na ako sa takot na uulit kami next semester. "
        "Plot twist, pagpasok namin sa room, sinabi nilang pasado kami with flying colors at best thesis pa! "
        "Napahagulgol talaga ako sa harap ng prof namin na parang baliw, pero tears of joy pala dahil tapos "
        "na ang kalbaryo namin—sobrang slay ng ending na ito, dasurv!"
    )
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.01, 0.02))
    res_untranslated = analyzer(case1)
    assert res_untranslated["risk_level"] == "LOW"
    assert 1 <= res_untranslated["risk_score"] <= 30

    gate = analyzer.__globals__["translation_gate"]
    monkeypatch.setitem(
        analyzer.__globals__,
        "_translate_to_english",
        lambda _text: (_ for _ in ()).throw(ConnectionError("offline")),
    )
    res_failed_translator = analyzer(case1)
    assert res_failed_translator["risk_level"] == "LOW"

    monkeypatch.setitem(analyzer.__globals__, "_get_positive_emotion_conf", lambda *args: 0.85)
    monkeypatch.setattr(
        gate,
        "translate",
        lambda text, _fn: (
            "I wanted to give up and disappear earlier while waiting for the panel's deliberation, "
            "because I thought we failed. Plot twist, when we entered the room, they said we passed "
            "with flying colors and best thesis too! I burst into tears, but it was tears of joy "
            "because our ordeal is over."
        ),
    )
    res_translated = analyzer(case1)
    assert res_translated["risk_level"] == "LOW"
    assert 1 <= res_translated["risk_score"] <= 30


def test_case2_sustained_daily_distress_is_high_triage_not_medium_or_critical(analyzer, monkeypatch):
    case2 = (
        "Sobrang sakit na sa pakiramdam na kahit ibigay mo na ang lahat sa mga taong mahalaga sa'yo, "
        "sa dulo ay iiwan ka pa rin nilang mag-isa. Araw-araw akong gumigising na parang durog na durog "
        "ang pagkatao ko at wala nang natitirang rason para bumangon pa. Pagod na pagod na akong "
        "magkunwaring malakas habang unti-unti na akong nilalamon ng lungkot at dilim sa loob ng kwarto ko. "
        "Pakiramdam ko ay wala akong silbi at hindi ako karapat-dapat mahalin ng kahit sino. Gusto ko na lang "
        "matapos itong walang katapusang paghihirap dahil hindi ko na talaga kayang dalhin pa ang bigat sa dibdib ko."
    )
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.99, 0.01))
    res_raw = analyzer(case2)
    assert res_raw["risk_level"] == "HIGH"
    assert 61 <= res_raw["risk_score"] <= 85

    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.99, 0.80))
    res_trans = analyzer(case2)
    assert res_trans["risk_level"] == "HIGH"
    assert 61 <= res_trans["risk_score"] <= 85


@pytest.mark.parametrize("text", [
    "Sometimes I wish I could just disappear from everything, even though I won't hurt myself.",
    "I thought we would fail earlier but we passed with flying colors! Still, gusto ko na talagang maglaho na lang.",
])
def test_spaced_and_post_pivot_passive_disappearance_is_high_79(analyzer, text):
    res = analyzer(text)
    assert res["risk_level"] == "HIGH"
    assert int(res["risk_score"]) == 79


@pytest.mark.parametrize("text", [
    "Passed the exam today with flying colors, pero gusto ko na talagang mamatay and end my life tonight.",
    "I had tears of joy earlier, pero naglalaslas ako ngayon.",
])
def test_mixed_positive_and_active_crisis_remains_critical(analyzer, text):
    assert analyzer(text)["risk_level"] == "CRITICAL"


@pytest.mark.parametrize("text", [
    "Namatay ako sa kakatawa sa meme na yun haha!",
    "I'm dead tired after PE practice, time to sleep.",
    "I do not want to kill myself, I am sharing this only to clarify.",
    "Years ago I used to say I want to kill myself. I am safe now.",
])
def test_hyperbole_negation_and_resolved_past_are_not_critical(analyzer, text):
    assert analyzer(text)["risk_level"] != "CRITICAL"


def test_scores_differentiate_across_severity_levels_without_clustering(analyzer, monkeypatch):
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.05, 0.05))
    low_pos = analyzer("Ang saya ng weekend namin kasama friends!")
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.28, 0.22))
    low_school = analyzer("Had a tiring week with schoolwork.")
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.15, 0.10))
    med_mild = analyzer("Stressed about quizzes tomorrow.")
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.99, 0.80))
    high_case2 = analyzer(
        "Araw-araw akong gumigising na parang durog na durog ang pagkatao ko at wala nang natitirang "
        "rason para bumangon pa. Wala akong silbi at hindi ako karapat-dapat mahalin. Gusto ko na lang "
        "matapos itong walang katapusang paghihirap."
    )
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.01, 0.01))
    crit_sarcasm = analyzer("i surely do want to kill my self wow amazing lupettt i love ittt!!")
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.99, 0.85))
    crit_acute = analyzer("papakamatay na ako at hindi ko na kaya mabuhay ngayon, hopeless na ako, pagod na")

    assert 1 <= low_pos["risk_score"] < low_school["risk_score"] <= 30
    assert 31 <= med_mild["risk_score"] < 60
    assert 61 <= high_case2["risk_score"] <= 85
    assert 86 <= crit_sarcasm["risk_score"] < crit_acute["risk_score"] <= 100


def test_risk_history_escalation_in_pipeline(analyzer, monkeypatch):
    from datetime import datetime, timedelta, timezone

    now = datetime.now(timezone.utc)
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.20, 0.15))

    escalating_med = [
        {"risk_level": "LOW", "created_at": (now - timedelta(days=4)).isoformat()},
        {"risk_level": "MEDIUM", "created_at": (now - timedelta(days=2)).isoformat()},
    ]
    res_med = analyzer("Nalulungkot ako today", student_id="stu-1", risk_history=escalating_med)
    assert res_med["risk_level"] == "HIGH"
    assert 61 <= res_med["risk_score"] <= 85

    escalating_high = [
        {"risk_level": "MEDIUM", "created_at": (now - timedelta(days=6)).isoformat()},
        {"risk_level": "HIGH", "created_at": (now - timedelta(days=3)).isoformat()},
        {"risk_level": "HIGH", "created_at": (now - timedelta(days=1)).isoformat()},
    ]
    res_high = analyzer("I wish I could disappear.", student_id="stu-2", risk_history=escalating_high)
    assert res_high["risk_level"] == "CRITICAL"
    assert res_high["risk_score"] >= 86

    stable_high = [
        {"risk_level": "HIGH", "created_at": (now - timedelta(days=10)).isoformat()},
        {"risk_level": "HIGH", "created_at": (now - timedelta(days=7)).isoformat()},
        {"risk_level": "HIGH", "created_at": (now - timedelta(days=5)).isoformat()},
        {"risk_level": "HIGH", "created_at": (now - timedelta(days=3)).isoformat()},
        {"risk_level": "HIGH", "created_at": (now - timedelta(days=1)).isoformat()},
    ]
    res_stable = analyzer("I wish I could disappear.", student_id="stu-3", risk_history=stable_high)
    assert res_stable["risk_level"] == "HIGH"


def test_translation_failure_degrades_gracefully(analyzer, monkeypatch):
    def failing_translate(_value):
        raise ConnectionError("network disabled")

    monkeypatch.setitem(analyzer.__globals__, "_translate_to_english", failing_translate)
    res = analyzer("papakamatay na ako ngayon")
    assert res["risk_level"] == "CRITICAL"


def test_public_openapi_and_docs_endpoints_are_disabled(analyzer):
    from fastapi.testclient import TestClient

    client = TestClient(analyzer.__globals__["app"])
    for hidden_path in ("/openapi.json", "/docs", "/redoc"):
        assert client.get(hidden_path).status_code == 404
    root = client.get("/")
    assert root.status_code == 200
    assert "api_docs" not in root.json()


def test_normalize_slang_preserves_english_possessive_my(analyzer):
    normalize = analyzer.__globals__["normalize_slang"]
    assert normalize("Passed all my exams today!") == "passed all my exams today!"


def test_low_band_scales_subthreshold_scores_without_pinning_at_one(analyzer, monkeypatch):
    monkeypatch.setitem(analyzer.__globals__, "get_risk_components", lambda *args: (0.24, 0.20))
    res = analyzer("Had a long week at school.")
    assert res["risk_level"] == "LOW"
    assert 5 <= res["risk_score"] <= 30


def test_get_risk_components_uses_top_k_none_and_caches_positive_confidence(monkeypatch):
    service_dir = Path(__file__).resolve().parents[1]
    monkeypatch.syspath_prepend(str(service_dir))
    emo_calls = []

    def fake_emo(text, **kwargs):
        emo_calls.append(kwargs)
        return [[
            {"label": "neutral", "score": 0.60},
            {"label": "joy", "score": 0.70},
            {"label": "gratitude", "score": 0.40},
            {"label": "sadness", "score": 0.25},
        ]]

    def fake_dist(text, **kwargs):
        return [[
            {"label": "LABEL_0", "score": 0.72},
            {"label": "LABEL_1", "score": 0.28},
        ]]

    classifier = SimpleNamespace(
        tokenizer=lambda *args, **kwargs: {"attention_mask": torch.ones((1, 1))},
        model=SimpleNamespace(base_model=lambda **kwargs: SimpleNamespace(last_hidden_state=torch.zeros((1, 1, 768)))),
    )
    monkeypatch.setitem(sys.modules, "transformers", SimpleNamespace(pipeline=lambda *args, **kwargs: classifier))
    spec = importlib.util.spec_from_file_location("risk_rules_single_pass", service_dir / "main.py")
    module = importlib.util.module_from_spec(spec)
    with monkeypatch.context() as load_patch:
        load_patch.setattr("nltk.data.find", lambda *args, **kwargs: True)
        spec.loader.exec_module(module)
    monkeypatch.setattr(module.nltk, "sent_tokenize", lambda text: [text])
    monkeypatch.setattr(module, "emotion_classifier", fake_emo)
    monkeypatch.setattr(module, "distress_classifier", fake_dist)
    monkeypatch.setattr(module.translation_gate, "translate", lambda text, _fn: text)

    res = module.analyze_text_sync("Had a long day at school but grateful for my friends.")
    assert len(emo_calls) == 1
    assert emo_calls[0].get("top_k") is None
    assert res["risk_level"] == "LOW"
    assert res["risk_score"] > 1.0
