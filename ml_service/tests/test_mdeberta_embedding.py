import os
import pytest
import numpy as np
import torch
from unittest.mock import MagicMock, patch

# Ensure ML_API_KEY is set before importing main
os.environ["ML_API_KEY"] = "test_api_key"

from ml_service.main import get_mdeberta_embedding


class DummyOutputWithLastHiddenState:
    def __init__(self, tensor):
        self.last_hidden_state = tensor


def create_mock_classifier(model_has_attr=None, output_type="last_hidden_state"):
    """
    Helper to create a mock distress_classifier object.
    model_has_attr can be 'deberta', 'base_model', or None.
    output_type can be 'last_hidden_state' or 'tuple'.
    """
    mock_classifier = MagicMock()

    # Setup tokenizer mock
    def mock_tokenizer(text, return_tensors="pt", truncation=True, max_length=512):
        return {
            "input_ids": torch.tensor([[101, 102, 103]]),
            "attention_mask": torch.tensor([[1, 1, 1]]),
        }

    mock_classifier.tokenizer = MagicMock(side_effect=mock_tokenizer)

    # Setup model mock
    mock_model = MagicMock(spec=[])

    # Sample last hidden state tensor of shape (batch_size=1, seq_len=3, hidden_size=768)
    hidden_tensor = torch.ones((1, 3, 768), dtype=torch.float32) * 2.0

    if output_type == "last_hidden_state":
        output_obj = DummyOutputWithLastHiddenState(hidden_tensor)
    else:
        output_obj = (hidden_tensor,)

    forward_mock = MagicMock(return_value=output_obj)

    if model_has_attr == "deberta":
        mock_model.deberta = forward_mock
    elif model_has_attr == "base_model":
        mock_model.base_model = forward_mock
    else:
        # Calls mock_model directly
        mock_model.side_effect = lambda **kwargs: output_obj

    mock_classifier.model = mock_model
    return mock_classifier, forward_mock if model_has_attr else mock_model


def test_get_mdeberta_embedding_with_deberta_attr():
    """Test model when it has .deberta attribute and returns last_hidden_state."""
    mock_classifier, forward_mock = create_mock_classifier(
        model_has_attr="deberta", output_type="last_hidden_state"
    )

    with patch("ml_service.main.distress_classifier", mock_classifier):
        embedding = get_mdeberta_embedding("Test text")

        assert isinstance(embedding, np.ndarray)
        assert embedding.shape == (768,)
        assert embedding.dtype == np.float32
        # Mean pooling of 2.0 over 3 tokens should yield 2.0 for all dimensions
        np.testing.assert_allclose(embedding, np.full((768,), 2.0, dtype=np.float32))
        assert forward_mock.called


def test_get_mdeberta_embedding_with_base_model_attr():
    """Test model when it has .base_model attribute and returns tuple/indexed output."""
    mock_classifier, forward_mock = create_mock_classifier(
        model_has_attr="base_model", output_type="tuple"
    )

    with patch("ml_service.main.distress_classifier", mock_classifier):
        embedding = get_mdeberta_embedding("Sample text")

        assert isinstance(embedding, np.ndarray)
        assert embedding.shape == (768,)
        np.testing.assert_allclose(embedding, np.full((768,), 2.0, dtype=np.float32))
        assert forward_mock.called


def test_get_mdeberta_embedding_without_deberta_or_base_model_attr():
    """Test model when it has neither .deberta nor .base_model attribute."""
    mock_classifier, mock_model = create_mock_classifier(
        model_has_attr=None, output_type="last_hidden_state"
    )

    with patch("ml_service.main.distress_classifier", mock_classifier):
        embedding = get_mdeberta_embedding("Another sample text")

        assert isinstance(embedding, np.ndarray)
        assert embedding.shape == (768,)
        np.testing.assert_allclose(embedding, np.full((768,), 2.0, dtype=np.float32))
        assert mock_model.called


def test_get_mdeberta_embedding_exception_fallback():
    """Test exception handling returning np.zeros(768)."""
    mock_classifier = MagicMock()
    mock_classifier.tokenizer.side_effect = Exception("Tokenizer failed")

    with patch("ml_service.main.distress_classifier", mock_classifier):
        embedding = get_mdeberta_embedding("Failing input")

        assert isinstance(embedding, np.ndarray)
        assert embedding.shape == (768,)
        assert embedding.dtype == np.float32
        np.testing.assert_array_equal(embedding, np.zeros(768, dtype=np.float32))
