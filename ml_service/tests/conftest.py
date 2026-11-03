import os
import sys

# Ensure ML_API_KEY environment variable is set for ml_service.main
if not os.getenv("ML_API_KEY"):
    os.environ["ML_API_KEY"] = "studentshield_secret_key_2026"

# Add ml_service directory to sys.path
ml_service_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if ml_service_dir not in sys.path:
    sys.path.insert(0, ml_service_dir)
