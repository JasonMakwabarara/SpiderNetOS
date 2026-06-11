import asyncio
import json
from uuid import UUID
from fastapi import FastAPI, Depends, BackgroundTasks
import asyncpg
import redis.asyncio as redis
from typing import Dict, Any

app = FastAPI(title="SpiderNetOS Atlas Perception Engine")

async def get_db_pool():
    pool = await asyncpg.create_pool(
        "postgresql://postgres:postgres@postgres:5432/spidernet"
    )
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

@app.post("/v2/perceive/stage-telemetry")
async def process_stage_telemetry(
    workspace_id: UUID,
    list_id: UUID,
    background_tasks: BackgroundTasks,
    db_pool: asyncpg.Pool = Depends(get_db_pool),
    redis_client: redis.Redis = Depends(get_redis)
):
    query = """
        SELECT
            stage,
            COUNT(*) as item_count,
            EXTRACT(EPOCH FROM AVG(NOW() - created_at)) / 86400 as avg_days_in_stage
        FROM crm_list_entries
        WHERE list_id = $1
        GROUP BY stage;
    """
    async with db_pool.acquire() as conn:
        rows = await conn.fetch(query, list_id)

    telemetry_summary = {
        row['stage']: {
            "count": row['item_count'],
            "avg_days": float(row['avg_days_in_stage'])
        } for row in rows
    }

    payload = {
        "workspace_id": str(workspace_id),
        "list_id": str(list_id),
        "metrics": telemetry_summary
    }

    await redis_client.xadd(
        f"stream:atlas:perception:{workspace_id}",
        {"data": json.dumps(payload)}
    )

    return {"status": "telemetry_cached", "target_list": str(list_id)}

@app.get("/health")
async def health():
    return {"status": "healthy"}
