import asyncpg
import logging

class RuntimeGuardian:
    def __init__(self, db_pool: asyncpg.Pool):
        self.pool = db_pool

    async def audit_workflow_health(self, workspace_id: str, playbook_id: str):
        query = """
            SELECT
                COUNT(*) FILTER (WHERE status = 'failed')::numeric / COUNT(*)::numeric as failure_rate
            FROM crm_workflow_execution_logs
            WHERE playbook_id = $1 AND executed_at > NOW() - INTERVAL '15 minutes';
        """
        async with self.pool.acquire() as conn:
            fail_rate = await conn.fetchval(query, playbook_id)

        if fail_rate and fail_rate > 0.05:
            logging.warning(f"Safety breach: Playbook {playbook_id} failure rate at {fail_rate}. Triggering rollback.")
            await conn.execute(
                """
                UPDATE workspace_autonomy_settings
                SET autonomy_level = 1
                WHERE workspace_id = $1;
                """, workspace_id
            )
            await self.execute_circuit_breaker_rollback(workspace_id, playbook_id)

    async def execute_circuit_breaker_rollback(self, workspace_id: str, playbook_id: str):
        logging.info(f"Circuit breaker activated for workspace {workspace_id}, playbook {playbook_id}")
