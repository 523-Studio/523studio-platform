import os

import joblib
import pandas as pd
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel

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

app = FastAPI()
_model = None


def get_model():
    # Load sekali per proses (bukan per request) - joblib.load() bukan operasi
    # murah, dan proses uvicorn ini tetap hidup antar-request selama belum tidur.
    global _model
    if _model is None:
        _model = joblib.load(MODEL_PATH)
    return _model


class PredictRequest(BaseModel):
    items: list[dict]


@app.post("/predict")
def predict(payload: PredictRequest, x_api_key: str | None = Header(default=None)):
    if API_KEY and x_api_key != API_KEY:
        raise HTTPException(status_code=401, detail="Invalid API key")

    items = payload.items
    if not items:
        return []

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

    return results


@app.get("/health")
def health():
    return {"status": "ok"}
