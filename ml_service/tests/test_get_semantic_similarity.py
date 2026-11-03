import os
import pytest
import numpy as np
from unittest.mock import patch, MagicMock

# Ensure ML_API_KEY is set before importing main
os.environ["ML_API_KEY"] = "test-api-key"

from ml_service.main import get_semantic_similarity, CRISIS_EMBEDDINGS_NORM


def test_get_semantic_similarity_empty_string():
    assert get_semantic_similarity("") == 0.0
    assert get_semantic_similarity("   ") == 0.0


@patch("ml_service.main.get_mdeberta_embedding")
def test_get_semantic_similarity_single_sentence(mock_embedding):
    # Mock embedding with same dimension as CRISIS_EMBEDDINGS_NORM (768)
    embedding_dim = CRISIS_EMBEDDINGS_NORM.shape[1]
    dummy_embedding = np.ones(embedding_dim, dtype=np.float32)
    mock_embedding.return_value = dummy_embedding

    score = get_semantic_similarity("I am feeling really sad today.")

    assert isinstance(score, float)
    assert 0.0 <= score <= 1.0
    mock_embedding.assert_called_once_with("I am feeling really sad today.")


@patch("ml_service.main.get_mdeberta_embedding")
def test_get_semantic_similarity_multiple_sentences(mock_embedding):
    embedding_dim = CRISIS_EMBEDDINGS_NORM.shape[1]

    # Return different embeddings for sentence 1 vs sentence 2
    vec1 = np.ones(embedding_dim, dtype=np.float32)
    vec2 = np.zeros(embedding_dim, dtype=np.float32)
    vec2[0] = 1.0

    mock_embedding.side_effect = [vec1, vec2]

    text = "Hello world. I am feeling hopeless and sad."
    score = get_semantic_similarity(text)

    assert isinstance(score, float)
    assert mock_embedding.call_count == 2


@patch("ml_service.main.get_mdeberta_embedding")
def test_get_semantic_similarity_zero_vector(mock_embedding):
    embedding_dim = CRISIS_EMBEDDINGS_NORM.shape[1]
    zero_embedding = np.zeros(embedding_dim, dtype=np.float32)
    mock_embedding.return_value = zero_embedding

    score = get_semantic_similarity("Zero embedding test sentence.")

    assert isinstance(score, float)
    assert not np.isnan(score)


@patch("ml_service.main.get_mdeberta_embedding")
def test_get_semantic_similarity_exception_handling(mock_embedding):
    mock_embedding.side_effect = Exception("Embedding model error")

    score = get_semantic_similarity("This should trigger exception handling.")

    assert score == 0.0
