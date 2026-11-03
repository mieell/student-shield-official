import pytest
from unittest.mock import MagicMock, patch
import main


def test_analyze_text_sync_translation_exception_fallback():
    """Verify analyze_text_sync falls back to normalized_text when GoogleTranslator raises an Exception."""
    mock_translator_instance = MagicMock()
    mock_translator_instance.translate.side_effect = Exception("Translation service unavailable")

    raw_text = "naliyak ako besh"
    expected_normalized = main.normalize_slang(raw_text)

    with patch("main.GoogleTranslator", return_value=mock_translator_instance), \
         patch("main.get_risk_components", return_value=(0.2, 0.2)) as mock_get_risk, \
         patch("main.logger.warning") as mock_logger_warn:

        result = main.analyze_text_sync(raw_text)

        # Check translator was instantiated and translate was called with normalized text
        mock_translator_instance.translate.assert_called_once_with(expected_normalized)

        # Check logger warning was issued
        mock_logger_warn.assert_called_once()
        assert "Translation failed, falling back to normalized" in mock_logger_warn.call_args[0][0]

        # Check get_risk_components received translated_text equal to normalized_text
        mock_get_risk.assert_called_once_with(expected_normalized, expected_normalized)

        # Check returned dictionary contains required keys
        assert "risk_score" in result
        assert "risk_level" in result
        assert "flag_reason" in result


@pytest.mark.parametrize("empty_response", ["", "   ", "\n\t", None])
def test_analyze_text_sync_empty_translation_fallback(empty_response):
    """Verify analyze_text_sync falls back to normalized_text when translation returns empty, whitespace, or None."""
    mock_translator_instance = MagicMock()
    mock_translator_instance.translate.return_value = empty_response

    raw_text = "naliyak ako besh"
    expected_normalized = main.normalize_slang(raw_text)

    with patch("main.GoogleTranslator", return_value=mock_translator_instance), \
         patch("main.get_risk_components", return_value=(0.2, 0.2)) as mock_get_risk:

        result = main.analyze_text_sync(raw_text)

        mock_translator_instance.translate.assert_called_once_with(expected_normalized)
        # get_risk_components should receive (normalized_text, normalized_text)
        mock_get_risk.assert_called_once_with(expected_normalized, expected_normalized)
        assert "risk_score" in result


def test_analyze_text_sync_successful_translation():
    """Verify analyze_text_sync uses translated_text when GoogleTranslator succeeds."""
    mock_translator_instance = MagicMock()
    mock_translated_text = "I cried friend"
    mock_translator_instance.translate.return_value = mock_translated_text

    raw_text = "naliyak ako besh"
    expected_normalized = main.normalize_slang(raw_text)

    with patch("main.GoogleTranslator", return_value=mock_translator_instance), \
         patch("main.get_risk_components", return_value=(0.2, 0.2)) as mock_get_risk:

        result = main.analyze_text_sync(raw_text)

        mock_translator_instance.translate.assert_called_once_with(expected_normalized)
        mock_get_risk.assert_called_once_with(mock_translated_text, expected_normalized)
        assert "risk_score" in result


def test_analyze_text_sync_slang_normalization_before_translation():
    """Verify slang normalization happens prior to calling GoogleTranslator."""
    mock_translator_instance = MagicMock()
    mock_translator_instance.translate.return_value = "I am sad"

    raw_text = "na-sad ako"
    expected_normalized = "malungkot ako"  # na-sad is normalized to malungkot

    with patch("main.GoogleTranslator", return_value=mock_translator_instance), \
         patch("main.get_risk_components", return_value=(0.2, 0.2)):

        main.analyze_text_sync(raw_text)

        # Verify GoogleTranslator.translate received normalized text, not raw text
        mock_translator_instance.translate.assert_called_once_with(expected_normalized)


def test_analyze_text_sync_end_to_end_fallback_execution():
    """Integration check verifying complete analysis pipeline succeeds with mocked failing translator."""
    mock_translator_instance = MagicMock()
    mock_translator_instance.translate.side_effect = RuntimeError("Network timeout connecting to translation server")

    with patch("main.GoogleTranslator", return_value=mock_translator_instance):
        result = main.analyze_text_sync("sobrang lungkot ko today")

        assert isinstance(result, dict)
        assert isinstance(result["risk_score"], float)
        assert result["risk_level"] in ["LOW", "MEDIUM", "HIGH", "CRITICAL"]
        assert "flag_reason" in result
