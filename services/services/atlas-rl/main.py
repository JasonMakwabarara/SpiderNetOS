from fastapi import FastAPI, Depends
import asyncpg

app = FastAPI(title="Atlas RL Reward Engine")

async def get_db_pool():
    pool = await asyncpg.create_pool("postgresql://postgres:postgres@postgres:5432/spidernet")
    try:
        yield pool
    finally:
        await pool.close()

@app.post("/update-policy")
async def update_policy(db_pool=Depends(get_db_pool)):
    # Aggregate rewards and update some policy table (mock)
    async with db_pool.acquire() as conn:
        await conn.execute("UPDATE atlas_policy_updates SET processed_at = NOW() WHERE processed_at IS NULL")
    return {"status": "policy_updated"}

@app.get("/health")
async def health():
    return {"status": "healthy"}