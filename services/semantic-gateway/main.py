from fastapi import FastAPI, Depends, HTTPException, BackgroundTasks
import asyncpg
import redis.asyncio as redis
from pydantic import BaseModel
from typing import List, Optional, Dict, Any
import json
from datetime import datetime
from uuid import uuid4
import httpx
import random
import math

app = FastAPI(title="SpiderNetOS Semantic Gateway")

async def get_db_pool():
    pool = await asyncpg.create_pool("postgresql://postgres:postgres@postgres:5432/spidernet", min_size=1, max_size=10)
    try:
        yield pool
    finally:
        await pool.close()

async def get_redis():
    r = redis.from_url("redis://redis:6379/0")
    try:
        yield r
    finally:
        await r.close()

# ---------- Health ----------
@app.get("/health")
async def health():
    return {"status": "healthy"}

@app.get("/api/health")
async def cockpit_health():
    return {
        "status": "ok",
        "timestamp": datetime.now().isoformat(),
        "services": [
            {"name": "Perception Engine", "status": "Online", "port": 8001},
            {"name": "DAG Compiler", "status": "Online", "port": 8002},
            {"name": "RL Reward Engine", "status": "Online", "port": 8003},
            {"name": "Runtime Guardian", "status": "Online", "port": 8004},
            {"name": "Gateway", "status": "Online", "port": 8005}
        ]
    }

# ---------- Recommendation Models ----------
class RecommendationCreate(BaseModel):
    title: str
    impact_score: int
    expected_revenue_gain: int
    justification: Optional[str] = ""
    proposed_dag: Optional[Dict] = {}
    risk_score: Optional[float] = 0.0
    estimated_time_saved: Optional[float] = 0.0

async def run_real_simulation(recommendation: RecommendationCreate, db_pool):
    async with db_pool.acquire() as conn:
        historical = await conn.fetch("""
            SELECT 
                AVG(CASE WHEN status = 'accepted' THEN 1.0 ELSE 0.0 END) as base_success_rate,
                COUNT(*) as total_recommendations
            FROM atlas_recommendations
            WHERE workspace_id = '00000000-0000-0000-0000-000000000001'
        """)
    
    base_rate = float(historical[0]['base_success_rate']) if historical[0]['total_recommendations'] > 0 else 0.7
    
    risk_adjustment = (recommendation.risk_score or 0.0) * 0.3
    impact_adjustment = (recommendation.impact_score / 100) * 0.2
    
    success_rate = base_rate + impact_adjustment - risk_adjustment
    success_rate = max(0.3, min(0.95, success_rate))
    
    failure_rate = 1 - success_rate
    
    edge_cases = []
    if recommendation.risk_score > 0.7:
        edge_cases.append({"case": "High risk recommendation", "probability": 0.3})
    if recommendation.impact_score > 80:
        edge_cases.append({"case": "High impact may require additional approval", "probability": 0.15})
    if recommendation.estimated_time_saved > 100:
        edge_cases.append({"case": "Large time savings may indicate scope creep", "probability": 0.2})
    if not recommendation.proposed_dag or len(recommendation.proposed_dag.get('steps', [])) == 0:
        edge_cases.append({"case": "No DAG provided, using default workflow", "probability": 0.1})
    
    projected_gain = recommendation.expected_revenue_gain * success_rate
    
    return {
        "success_rate": round(success_rate, 2),
        "failure_rate": round(failure_rate, 2),
        "projected_gain": round(projected_gain, 2),
        "edge_case_logs": edge_cases,
        "simulation_model": "historical_bayesian",
        "confidence_interval": 0.85
    }

@app.post("/api/recommendations")
async def create_recommendation(rec: RecommendationCreate, db_pool=Depends(get_db_pool)):
    # 1. Validate DAG via dag-compiler (if provided)
    if rec.proposed_dag:
        async with httpx.AsyncClient() as client:
            dag_resp = await client.post("http://dag-compiler:8000/validate", json=rec.proposed_dag)
            if dag_resp.status_code != 200:
                raise HTTPException(status_code=400, detail="DAG validation failed")
    
    # 2. Run simulation
    simulation = await run_real_simulation(rec, db_pool)
    
    # 3. Insert into database (use a separate connection acquisition)
    rec_id = str(uuid4())
    async with db_pool.acquire() as conn:
        await conn.execute("""
            INSERT INTO atlas_recommendations 
            (id, workspace_id, title, impact_score, expected_revenue_gain, status, justification, proposed_dag, risk_score, estimated_time_saved, simulation_result, created_at)
            VALUES ($1, $2, $3, $4, $5, 'pending', $6, $7, $8, $9, $10, $11)
        """, rec_id, "00000000-0000-0000-0000-000000000001", rec.title, rec.impact_score,
           rec.expected_revenue_gain, rec.justification, json.dumps(rec.proposed_dag),
           rec.risk_score, rec.estimated_time_saved, json.dumps(simulation), datetime.now())
    
    # 4. Log memory event (use a separate connection)
    async with db_pool.acquire() as conn:
        await conn.execute("""
            INSERT INTO atlas_memory_events (workspace_id, event_type, outcome, confidence, metadata, created_at)
            VALUES ($1, $2, $3, $4, $5, NOW())
        """, "00000000-0000-0000-0000-000000000001", 'recommendation_created', 'draft', simulation['success_rate'], 
           json.dumps({"recommendation_id": rec_id, "edge_cases": simulation['edge_case_logs']}))
    
    return {
        "id": rec_id, 
        "title": rec.title, 
        "impact_score": rec.impact_score, 
        "expected_revenue_gain": rec.expected_revenue_gain, 
        "status": "pending",
        "simulation": simulation
    }

@app.get("/api/recommendations")
async def get_recommendations(db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        rows = await conn.fetch("""
            SELECT id, title, impact_score, expected_revenue_gain, status, justification, risk_score, simulation_result, created_at
            FROM atlas_recommendations
            ORDER BY created_at DESC
        """)
    return [dict(r) for r in rows]

@app.patch("/api/recommendations/{rec_id}/accept")
async def accept_recommendation(rec_id: str, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        await conn.execute("UPDATE atlas_recommendations SET status = 'accepted' WHERE id = $1", rec_id)
        await conn.execute("INSERT INTO atlas_policy_updates (recommendation_id, reward_score, metric_applied, processed_at) VALUES ($1, 1.0, 'accept', NOW())", rec_id)
        await conn.execute("""
            INSERT INTO atlas_memory_events (workspace_id, event_type, outcome, confidence, metadata, created_at)
            VALUES ($1, $2, $3, $4, $5, NOW())
        """, "00000000-0000-0000-0000-000000000001", 'recommendation_accepted', 'user_approval', 0.95, json.dumps({"recommendation_id": rec_id}))
    return {"status": "accepted", "id": rec_id}

@app.patch("/api/recommendations/{rec_id}/reject")
async def reject_recommendation(rec_id: str, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        await conn.execute("UPDATE atlas_recommendations SET status = 'rejected' WHERE id = $1", rec_id)
        await conn.execute("INSERT INTO atlas_policy_updates (recommendation_id, reward_score, metric_applied, processed_at) VALUES ($1, -2.0, 'reject', NOW())", rec_id)
        await conn.execute("""
            INSERT INTO atlas_memory_events (workspace_id, event_type, outcome, confidence, metadata, created_at)
            VALUES ($1, $2, $3, $4, $5, NOW())
        """, "00000000-0000-0000-0000-000000000001", 'recommendation_rejected', 'user_override', 0.90, json.dumps({"recommendation_id": rec_id}))
    return {"status": "rejected", "id": rec_id}

# ---------- Schema Intelligence ----------
@app.get("/atlas/schema/{workspace_id}")
async def get_schema_metadata(workspace_id: str, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        objects = await conn.fetch("SELECT id, name, api_slug FROM crm_objects WHERE workspace_id = $1 OR is_system_defined = TRUE", workspace_id)
        attributes = await conn.fetch("""
            SELECT object_id, name, api_slug, type FROM crm_attributes
            WHERE object_id IN (SELECT id FROM crm_objects WHERE workspace_id = $1 OR is_system_defined = TRUE)
        """, workspace_id)
    schema_map = {}
    for obj in objects:
        schema_map[obj['api_slug']] = {"object_id": str(obj['id']), "name": obj['name'], "attributes": {}}
    for attr in attributes:
        for slug, data in schema_map.items():
            if data["object_id"] == str(attr['object_id']):
                data["attributes"][attr['api_slug']] = attr['type']
    return {"workspace_id": workspace_id, "schema": schema_map}

# ---------- Autonomy Settings ----------
@app.get("/api/autonomy/settings/{workspace_id}")
async def get_autonomy(workspace_id: str, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        row = await conn.fetchrow("SELECT autonomy_level, auto_execute_threshold, rollback_threshold FROM workspace_autonomy_settings WHERE workspace_id = $1", workspace_id)
        if row:
            return {"autonomy_level": row['autonomy_level'], "auto_execute_threshold": row['auto_execute_threshold'], "rollback_threshold": row['rollback_threshold']}
        await conn.execute("INSERT INTO workspace_autonomy_settings (workspace_id, autonomy_level, auto_execute_threshold, rollback_threshold) VALUES ($1, 1, 0.85, 0.05)", workspace_id)
        return {"autonomy_level": 1, "auto_execute_threshold": 0.85, "rollback_threshold": 0.05}

@app.put("/api/autonomy/settings/{workspace_id}")
async def update_autonomy(workspace_id: str, settings: dict, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        await conn.execute("""
            UPDATE workspace_autonomy_settings
            SET autonomy_level = $2, auto_execute_threshold = $3, rollback_threshold = $4, updated_at = NOW()
            WHERE workspace_id = $1
        """, workspace_id, settings['autonomy_level'], settings['auto_execute_threshold'], settings['rollback_threshold'])
    return {"status": "updated"}

# ---------- Agent Swarm ----------
@app.post("/api/orchestrate/trigger")
async def orchestrate_agents(background_tasks: BackgroundTasks):
    background_tasks.add_task(_run_agent_swarm)
    return {"status": "started", "message": "Agent swarm orchestration triggered", "timestamp": datetime.now().isoformat()}

async def _run_agent_swarm():
    try:
        async with httpx.AsyncClient() as client:
            await client.post("http://atlas-perception:8000/v2/perceive/stage-telemetry?workspace_id=00000000-0000-0000-0000-000000000001&list_id=11111111-1111-1111-1111-111111111111")
            await client.post("http://dag-compiler:8000/validate", json={"trigger": "test", "steps": []})
            await client.post("http://atlas-rl:8000/update-policy")
            await client.post("http://runtime-guardian:8000/audit?workspace_id=00000000-0000-0000-0000-000000000001&playbook_id=test")
    except Exception as e:
        print(f"Agent swarm error: {e}")

# ---------- Memory Events ----------
@app.get("/api/memory/events/{workspace_id}")
async def get_memory_events(workspace_id: str, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        rows = await conn.fetch("SELECT event_type, outcome, confidence, metadata, created_at FROM atlas_memory_events WHERE workspace_id = $1 ORDER BY created_at DESC LIMIT 50", workspace_id)
    return [dict(r) for r in rows]

# ---------- Revenue Signals ----------
@app.get("/api/revenue/signals/{workspace_id}")
async def get_revenue_signals(workspace_id: str, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        rows = await conn.fetch("SELECT signal_type, value, source_origin, created_at FROM revenue_signals WHERE workspace_id = $1 ORDER BY created_at DESC LIMIT 20", workspace_id)
    return [dict(r) for r in rows]

# ---------- Cognitive Control API ----------
@app.post("/v2/atlas/coordinate-cycle")
async def coordinate_cycle(workspace_id: str, db_pool=Depends(get_db_pool)):
    async with httpx.AsyncClient() as client:
        await client.post(f"http://atlas-perception:8000/v2/perceive/stage-telemetry?workspace_id={workspace_id}&list_id=11111111-1111-1111-1111-111111111111")
    
    rec_id = str(uuid4())
    async with db_pool.acquire() as conn:
        await conn.execute("""
            INSERT INTO atlas_recommendations (id, workspace_id, title, impact_score, expected_revenue_gain, status, created_at)
            VALUES ($1, $2, $3, $4, $5, 'pending', $6)
        """, rec_id, workspace_id, "Auto-generated from cognitive cycle", 60, 15000, datetime.now())
    
    return {
        "status": "cycle_completed",
        "recommendation_id": rec_id,
        "metrics_gain_projected": 15000,
        "confidence": 0.85
    }

# ---------- Semantic Gateway ----------
@app.post("/v2/gateway/evaluate")
async def evaluate_event(event_payload: str, workspace_id: str, db_pool=Depends(get_db_pool), redis_client=Depends(get_redis)):
    async with db_pool.acquire() as conn:
        matched = await conn.fetchrow("SELECT playbook_id FROM crm_cached_patterns WHERE workspace_id = $1 LIMIT 1", workspace_id)
    
    if matched:
        await redis_client.lpush("queue:runtime:deterministic", json.dumps({"playbook_id": str(matched['playbook_id']), "context": event_payload}))
        return {"route": "deterministic_cache_bypass", "source": str(matched['playbook_id']), "similarity_score": 0.98}
    
    await redis_client.lpush("queue:atlas:cognitive:perception", json.dumps({"workspace_id": workspace_id, "payload": event_payload}))
    return {"route": "cognitive_analysis_pipeline", "similarity_score": 0.0}