import os

import joblib
import pandas as pd
from flask import Flask, jsonify, request

MODEL_PATH = os.path.join(os.path.dirname(__file__), "delay_risk_model.pkl")
API_KEY = os.environ.get("DELAY_RISK_API_KEY")

FEATURE_COLUMNS = [
    "client_category",
    "pillar",
    "content_complexity",
    "workload_pic_same_week",
    "current_status",
    "revision_count",
    "days_in_current_status",
]

# PythonAnywhere mengimpor `app` dari file ini sebagai WSGI application
# (lihat file WSGI config yang di-generate otomatis saat setup web app Flask
# di tab Web hPanel PythonAnywhere).
app = Flask(__name__)
_model = None


def get_model():
    # Load sekali per proses (bukan per request) - joblib.load() bukan operasi
    # murah, dan proses WSGI worker ini tetap hidup antar-request.
    global _model
    if _model is None:
        _model = joblib.load(MODEL_PATH)
    return _model


@app.route("/predict", methods=["POST"])
def predict():
    if API_KEY and request.headers.get("X-Api-Key") != API_KEY:
        return jsonify({"detail": "Invalid API key"}), 401

    payload = request.get_json(silent=True) or {}
    items = payload.get("items", [])
    if not items:
        return jsonify([])

    df = pd.DataFrame(items)
    X = df[FEATURE_COLUMNS]

    model = get_model()
    probabilities = model.predict_proba(X)[:, 1]

    results = []
    for i, prob in enumerate(probabilities):
        score = round(float(prob) * 100)
        if score >= 70:
            level = "high"
        elif score >= 40:
            level = "medium"
        else:
            level = "low"

        results.append({
            "content_item_id": items[i]["content_item_id"],
            "risk_score": score,
            "risk_level": level,
        })

    return jsonify(results)


@app.route("/health")
def health():
    return jsonify({"status": "ok"})
