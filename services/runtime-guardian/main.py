import logging

import asyncpg
from fastapi import Depends, FastAPI

app = FastAPI(title="Runtime Guardian")

async def get_db_pool():
    pool = await asyncpg.create_pool("postgresql://postgres:postgres@postgres:5432/spidernet")
    try:
        yield pool
    finally:
        await pool.close()

@app.post("/audit")
async def audit_workflow_health(workspace_id: str, playbook_id: str, db_pool=Depends(get_db_pool)):
    async with db_pool.acquire() as conn:
        fail_rate = await conn.fetchval("""
            SELECT COUNT(*) FILTER (WHERE status = 'failed')::numeric / NULLIF(COUNT(*), 0)::numeric
            FROM crm_workflow_execution_logs
            WHERE playbook_id = $1 AND executed_at > NOW() - INTERVAL '15 minutes'
        """, playbook_id)
        if fail_rate and fail_rate > 0.05:
            await conn.execute("UPDATE workspace_autonomy_settings SET autonomy_level = 1 WHERE workspace_id = $1", workspace_id)
            logging.warning(f"Autonomy demoted for workspace {workspace_id} due to failure rate {fail_rate}")
            return {"status": "demoted", "new_level": 1}
    return {"status": "healthy"}

@app.get("/health")
async def health():
    return {"status": "healthy"}
