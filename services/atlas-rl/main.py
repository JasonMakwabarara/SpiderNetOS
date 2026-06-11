from fastapi import FastAPI, Depends
from uuid import UUID
import asyncpg
from reward_router import AtlasRewardEngine

app = FastAPI(title="Atlas RL Reward Router")

async def get_db_pool():
    pool = await asyncpg.create_pool("postgresql://postgres:postgres@postgres:5432/spidernet")
    try:
        yield pool
    finally:
        await pool.close()

@app.post("/v2/reward/register")
async def register_reward(
    recommendation_id: UUID,
    outcome_metric: str,
    value_delta: float,
    db_pool: asyncpg.Pool = Depends(get_db_pool)
):
    engine = AtlasRewardEngine(db_pool)
    await engine.register_policy_feedback(recommendation_id, outcome_metric, value_delta)
    return {"status": "reward_registered"}

@app.get("/health")
async def health():
    return {"status": "healthy"}
