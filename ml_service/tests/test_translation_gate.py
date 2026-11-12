import sys
from pathlib import Path
from unittest.mock import Mock

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from translation_gate import TranslationGate, confidently_english


@pytest.mark.parametrize('text', [
    "I'm feeling very sad.",
    'I feel anxious about my exams tomorrow.',
])
def test_english_does_not_call_translator(text):
    translator = Mock(side_effect=AssertionError('No translation needed'))
    assert TranslationGate().translate(text, translator) == text
    translator.assert_not_called()


@pytest.mark.parametrize('text', [
    'nalulungkot ako', 'pagod na akong lumaban',
    'I feel very sad pero hindi ko alam kung bakit',
    'di nako makaya ang kasakit', 'mabannogakon ti panagbiag ko',
])
def test_local_language_and_mixed_text_still_translate(text):
    assert not confidently_english(text)
    translator = Mock(return_value='Translated text')
    assert TranslationGate().translate(text, translator) == 'Translated text'
    translator.assert_called_once_with(text)


def test_success_is_reused_then_expires_and_cache_is_bounded():
    time = [0]
    gate = TranslationGate(clock=lambda: time[0], english=lambda _: False, capacity=2)
    translator = Mock(side_effect=lambda value: 'translation ' + value)
    gate.translate('a', translator)
    gate.translate('a', translator)
    assert translator.call_count == 1
    time[0] = 301
    gate.translate('a', translator)
    assert translator.call_count == 2
    gate.translate('b', translator)
    gate.translate('c', translator)
    assert len(gate.cache) == 2
    assert all(isinstance(key, bytes) for key in gate.cache)


@pytest.mark.parametrize('response', [RuntimeError('rate limited'), '', '<html>Error</html>', 'Server Error', None])
def test_failure_retries_next_request_without_caching_fallback(response):
    time = [0]
    gate = TranslationGate(clock=lambda: time[0], english=lambda _: False)
    translator = Mock(side_effect=response) if isinstance(response, Exception) else Mock(return_value=response)
    assert gate.translate('original', translator) == 'original'
    assert not gate.cache
    recovered = Mock(return_value='Recovered translation')
    assert gate.translate('original', recovered) == 'Recovered translation'
    recovered.assert_called_once()


def test_busy_translation_falls_back_instead_of_launching_more_calls():
    gate = TranslationGate(english=lambda _: False)
    gate.busy = True
    translator = Mock()
    assert gate.translate('original', translator) == 'original'
    translator.assert_not_called()


def test_cached_translation_remains_available_during_outage():
    gate = TranslationGate(english=lambda _: False)
    assert gate.translate('cached', lambda _: 'good translation') == 'good translation'
    gate.translate('outage', Mock(side_effect=RuntimeError()))
    translator = Mock()
    assert gate.translate('cached', translator) == 'good translation'
    translator.assert_not_called()


def test_language_detection_failure_still_tries_translation():
    gate = TranslationGate(english=Mock(side_effect=RuntimeError()))
    assert gate.translate('original', lambda _: 'translated') == 'translated'
