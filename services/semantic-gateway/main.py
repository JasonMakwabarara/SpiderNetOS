from fastapi import FastAPI, Depends
import asyncpg
import redis.asyncio as redis
from router import evaluate_incoming_event

app = FastAPI(title="Semantic Gateway")

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

@app.post("/v2/gateway/evaluate")
async def evaluate_event(
    event_payload: str,
    workspace_id: str,
    db_pool: asyncpg.Pool = Depends(get_db_pool),
    redis_client: redis.Redis = Depends(get_redis)
):
    result = await evaluate_incoming_event(event_payload, workspace_id, db_pool, redis_client)
    return result

@app.get("/health")
async def health():
    return {"status": "healthy"}
