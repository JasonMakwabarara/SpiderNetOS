from fastapi import FastAPI, Depends
import asyncpg
from monitor import RuntimeGuardian

app = FastAPI(title="Runtime Guardian")

async def get_db_pool():
    pool = await asyncpg.create_pool("postgresql://postgres:postgres@postgres:5432/spidernet")
    try:
        yield pool
    finally:
        await pool.close()

@app.post("/v2/guardian/audit")
async def audit_workflow(
    workspace_id: str,
    playbook_id: str,
    db_pool: asyncpg.Pool = Depends(get_db_pool)
):
    guardian = RuntimeGuardian(db_pool)
    await guardian.audit_workflow_health(workspace_id, playbook_id)
    return {"status": "audited"}

@app.get("/health")
async def health():
    return {"status": "healthy"}
