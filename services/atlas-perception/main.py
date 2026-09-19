import json
from datetime import datetime
from uuid import UUID

import asyncpg
from fastapi import Depends, FastAPI

import redis.asyncio as redis

app = FastAPI(title="SpiderNetOS Atlas Perception Engine")

async def get_db_pool():
    pool = await asyncpg.create_pool("postgresql://postgres:postgres@postgres:5432/spidernet")
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
    db_pool=Depends(get_db_pool),
    redis_client=Depends(get_redis)
):
    # Real telemetry query from crm_list_entries
    query = """
        SELECT
            stage,
            COUNT(*) as item_count,
            COALESCE(EXTRACT(EPOCH FROM AVG(NOW() - created_at)) / 86400, 0) as avg_days_in_stage
        FROM crm_list_entries
        WHERE list_id = $1
        GROUP BY stage
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
        "metrics": telemetry_summary,
        "timestamp": datetime.now().isoformat()
    }
    await redis_client.xadd(f"stream:atlas:perception:{workspace_id}", {"data": json.dumps(payload)})
    return {"status": "telemetry_cached", "target_list": str(list_id), "metrics": telemetry_summary}

@app.get("/atlas/schema/{workspace_id}")
async def get_schema_metadata(workspace_id: UUID, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        objects = await conn.fetch("SELECT id, name, api_slug FROM crm_objects WHERE workspace_id = $1 OR is_system_defined = TRUE", workspace_id)
        attributes = await conn.fetch("SELECT object_id, name, api_slug, type FROM crm_attributes WHERE object_id IN (SELECT id FROM crm_objects WHERE workspace_id = $1 OR is_system_defined = TRUE)", workspace_id)
    schema_map = {}
    for obj in objects:
        schema_map[obj['api_slug']] = {"object_id": str(obj['id']), "name": obj['name'], "attributes": {}}
    for attr in attributes:
        for slug, data in schema_map.items():
            if data["object_id"] == str(attr['object_id']):
                data["attributes"][attr['api_slug']] = attr['type']
    return {"workspace_id": str(workspace_id), "schema": schema_map}
