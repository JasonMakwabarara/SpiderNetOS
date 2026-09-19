import json
import os
from datetime import datetime
from typing import List

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

router = APIRouter(tags=["cockpit"])

# Data file path (mounted volume for persistence)
DATA_DIR = "/app/data"
DATA_FILE = os.path.join(DATA_DIR, "recommendations.json")

# Ensure data directory exists
os.makedirs(DATA_DIR, exist_ok=True)

# Initialize sample data if file doesn't exist
if not os.path.exists(DATA_FILE):
    sample_data = [
        {"id": 1, "title": "Optimize EC2 Instance Sizing", "impact_score": 45, "expected_revenue_gain": 12500, "status": "pending", "created_at": "2025-06-10T10:00:00"},
        {"id": 2, "title": "Enable Reserved Instances", "impact_score": 68, "expected_revenue_gain": 47800, "status": "pending", "created_at": "2025-06-10T11:00:00"},
        {"id": 3, "title": "Implement S3 Lifecycle Policies", "impact_score": 32, "expected_revenue_gain": 3400, "status": "accepted", "created_at": "2025-06-11T09:00:00"},
        {"id": 4, "title": "Reduce EBS Snapshot Retention", "impact_score": 28, "expected_revenue_gain": 2800, "status": "pending", "created_at": "2025-06-11T14:00:00"},
        {"id": 5, "title": "Migrate to Graviton Processors", "impact_score": 55, "expected_revenue_gain": 23100, "status": "pending", "created_at": "2025-06-12T08:00:00"}
    ]
    with open(DATA_FILE, "w") as f:
        json.dump(sample_data, f, indent=2)

def read_recommendations():
    with open(DATA_FILE, "r") as f:
        return json.load(f)

def write_recommendations(data):
    with open(DATA_FILE, "w") as f:
        json.dump(data, f, indent=2)

# Request/Response Models
class RecommendationCreate(BaseModel):
    title: str
    impact_score: int
    expected_revenue_gain: int

class RecommendationResponse(BaseModel):
    id: int
    title: str
    impact_score: int
    expected_revenue_gain: int
    status: str
    created_at: str

# Health endpoint for cockpit
@router.get("/api/health")
async def cockpit_health():
    return {
        "status": "ok",
        "timestamp": datetime.now().isoformat(),
        "services": [
            {"name": "Perception Engine", "status": "Online", "port": 8001},
            {"name": "DAG Compiler", "status": "Online", "port": 8002},
            {"name": "RL Reward Engine", "status": "Online", "port": 8003},
            {"name": "Gateway", "status": "Online", "port": 8005}
        ]
    }

# Get all recommendations
@router.get("/api/recommendations", response_model=List[RecommendationResponse])
async def get_recommendations():
    return read_recommendations()

# Create new recommendation
@router.post("/api/recommendations", response_model=RecommendationResponse)
async def create_recommendation(rec: RecommendationCreate):
    data = read_recommendations()
    new_id = max([r["id"] for r in data], default=0) + 1
    new_rec = {
        "id": new_id,
        "title": rec.title,
        "impact_score": rec.impact_score,
        "expected_revenue_gain": rec.expected_revenue_gain,
        "status": "pending",
        "created_at": datetime.now().isoformat()
    }
    data.append(new_rec)
    write_recommendations(data)
    return new_rec

# Accept recommendation
@router.patch("/api/recommendations/{rec_id}/accept")
async def accept_recommendation(rec_id: int):
    data = read_recommendations()
    for rec in data:
        if rec["id"] == rec_id:
            rec["status"] = "accepted"
            write_recommendations(data)
            return {"status": "accepted", "id": rec_id, "message": "Recommendation accepted"}
    raise HTTPException(status_code=404, detail="Recommendation not found")

# Reject recommendation
@router.patch("/api/recommendations/{rec_id}/reject")
async def reject_recommendation(rec_id: int):
    data = read_recommendations()
    for rec in data:
        if rec["id"] == rec_id:
            rec["status"] = "rejected"
            write_recommendations(data)
            return {"status": "rejected", "id": rec_id, "message": "Recommendation rejected"}
    raise HTTPException(status_code=404, detail="Recommendation not found")

# Orchestrate agent swarm
@router.post("/api/orchestrate/trigger")
async def orchestrate_agents():
    return {
        "status": "started",
        "message": "Agent swarm orchestration triggered",
        "agents": ["perception", "dag_compiler", "rl_reward", "gateway"],
        "timestamp": datetime.now().isoformat()
    }
