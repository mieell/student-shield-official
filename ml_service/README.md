---
title: Student Shield Official
emoji: 🛡️
colorFrom: green
colorTo: blue
sdk: docker
app_port: 7860
pinned: false
---

# 🧠 StudentShield ML Microservice Management Guide


The **StudentShield ML Service** is a Python FastAPI service for multilingual Safe Space vent screening. It combines distress/emotion classifiers with contextual rules and database-backed risk history supplied by the PHP application. Screening bands are not diagnoses or probabilities of harm. Bullying-report verification is a separate PHP workflow.

---

## 📂 Microservice File Overview

| File / Folder | Purpose |
| :--- | :--- |
| `main.py` | Authenticated `/analyze-text` endpoint, `/` health endpoint, model inference, safety rules and rate limiting. |
| `requirements.txt` | Service and training dependencies; PyTorch, Transformers, FastAPI and supporting libraries. |
| `generate_dataset.py` | Script to build synthetic Tagalog/English dataset for training and evaluation. |
| `train_models.py` | Fine-tuning script for mDeBERTa-v3 / Transformer text classification models. |
| `evaluate_val.py` | Validation script to evaluate classification accuracy, precision, recall, and F1 score. |
| `StudentShield_Colab_Trainer.ipynb` | Google Colab Notebook for training models on cloud GPUs. |
| `risk_history.py` | Trajectory over prior risk labels/timestamps supplied from the web database. |
| `translation_gate.py` | English detection and short-lived translation reuse, with retry on the next request after a failure. |
| `data/crisis_reference_phrases.json` | Preserved reference phrases from an unused semantic-similarity prototype; not loaded or embedded during inference. |
| `models/` | Saved model checkpoints and tokenizer files. |
| `Dockerfile` | Container configuration for cloud deployment (Hugging Face Spaces, Docker, Cloud Run). |

---

## 🚀 1. Operating the Service

### A. Run Locally
```bash
# Navigate to ml_service directory
cd ml_service

# Activate virtual environment
# Windows:
.venv\Scripts\activate
# Linux/macOS:
source .venv/bin/activate

# Start server (Development mode with auto-reload)
uvicorn main:app --host 0.0.0.0 --port 8000 --reload
```

### B. Production Deployment (Docker)
```bash
# Build Docker image
docker build -t studentshield-ml .

# Run Container on Port 8000
docker run -d -p 8000:7860 --env ML_API_KEY --name studentshield-ml-container studentshield-ml
```

---

## 🧪 2. Testing Endpoints & API Documentation

Interactive OpenAPI documentation is available when the service is running:
- **Interactive Swagger UI**: [http://localhost:8000/docs](http://localhost:8000/docs)
- **ReDoc Documentation**: [http://localhost:8000/redoc](http://localhost:8000/redoc)
- **Health Check Endpoint**: `GET http://localhost:8000/`

### Sample Request (`POST /analyze-text`, with server-side `X-API-KEY` header):
```json
{
  "raw_text": "Fictional example for controlled testing.",
  "student_id": "synthetic-test",
  "risk_history": []
}
```

---

## 🏋️ 3. Retraining & Updating Models

If you need to retrain or fine-tune models with new student vent data:

1. **Prepare Dataset**:
   ```bash
   python generate_dataset.py
   ```
2. **Train Model**:
   ```bash
   python train_models.py
   ```
3. **Evaluate Performance**:
   ```bash
   python evaluate_val.py
   ```
4. **Train on Cloud GPU (Optional)**:
   Upload `StudentShield_Colab_Trainer.ipynb` to Google Colab and run with a free T4 GPU backend.

---

## 🔒 4. Maintenance & Monitoring

- **Rate Limiting**: Built-in `slowapi` rate limits protect API endpoints from denial-of-service.
- **Model Storage**: Model files stored in `models/` can be swapped or updated without changing the API contract.
- **Durable history**: PHP supplies prior risk labels and timestamps from its database. The service does not write per-student vector-memory files.
- **Startup cleanup**: Unused semantic embedding functions and precomputation were removed. Reference phrases were retained separately; the active crisis lexicons, model paths and scoring rules are unchanged by that cleanup.
