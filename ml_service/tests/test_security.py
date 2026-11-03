import os
import pytest
from fastapi import HTTPException
from fastapi.testclient import TestClient

# Ensure ML_API_KEY is set prior to importing main
os.environ["ML_API_KEY"] = "test_secret_key_123"

from ml_service.main import app, verify_api_key, API_KEY


@pytest.mark.asyncio
async def test_verify_api_key_valid():
    """Test verify_api_key succeeds and returns the key when provided a valid API key."""
    result = await verify_api_key(api_key="test_secret_key_123")
    assert result == "test_secret_key_123"


@pytest.mark.asyncio
async def test_verify_api_key_invalid():
    """Test verify_api_key raises 401 HTTPException when provided an invalid API key."""
    with pytest.raises(HTTPException) as exc_info:
        await verify_api_key(api_key="invalid_api_key")
    assert exc_info.value.status_code == 401
    assert exc_info.value.detail == "Invalid API Key"


def test_endpoint_missing_api_key_header():
    """Test request to protected endpoint fails when X-API-KEY header is missing."""
    client = TestClient(app)
    response = client.post("/analyze-text", json={"raw_text": "I am feeling ok"})
    assert response.status_code in (401, 403)


def test_endpoint_invalid_api_key_header():
    """Test request to protected endpoint fails when X-API-KEY header is invalid."""
    client = TestClient(app)
    response = client.post(
        "/analyze-text",
        headers={"X-API-KEY": "wrong_key"},
        json={"raw_text": "I am feeling ok"},
    )
    assert response.status_code == 401
    assert response.json() == {"detail": "Invalid API Key"}


def test_endpoint_valid_api_key_header():
    """Test request to protected endpoint passes security check with valid X-API-KEY header."""
    client = TestClient(app)
    response = client.post(
        "/analyze-text",
        headers={"X-API-KEY": "test_secret_key_123"},
        json={"raw_text": "I am feeling ok"},
    )
    assert response.status_code == 200
    data = response.json()
    assert "risk_level" in data
    assert "risk_score" in data


def test_missing_env_var_raises_runtime_error(monkeypatch):
    """Test that missing ML_API_KEY environment variable causes RuntimeError during startup check."""
    import importlib
    import ml_service.main

    monkeypatch.delenv("ML_API_KEY", raising=False)
    with pytest.raises(RuntimeError) as exc_info:
        importlib.reload(ml_service.main)
    assert "ML_API_KEY environment variable must be set." in str(exc_info.value)

    # Restore environment variable and reload module so other tests/state remain clean
    os.environ["ML_API_KEY"] = "test_secret_key_123"
    importlib.reload(ml_service.main)
