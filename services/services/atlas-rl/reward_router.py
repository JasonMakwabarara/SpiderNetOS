from uuid import UUID
import asyncpg

class AtlasRewardEngine:
    def __init__(self, db_pool: asyncpg.Pool):
        self.pool = db_pool

    async def register_policy_feedback(
        self,
        recommendation_id: UUID,
        outcome_metric: str,
        value_delta: float
    ):
        reward = 0.0
        if outcome_metric == "revenue_increase":
            reward += 1.0 * (1 if value_delta > 0 else -1)
        elif outcome_metric == "conversion_speed":
            reward += 1.0 * (1 if value_delta < 0 else -1)
        elif outcome_metric == "user_override":
            reward -= 2.0

        query = """
            INSERT INTO atlas_policy_updates (recommendation_id, reward_score, metric_applied, processed_at)
            VALUES ($1, $2, $3, NOW())
            ON CONFLICT (recommendation_id) DO UPDATE
            SET reward_score = atlas_policy_updates.reward_score + EXCLUDED.reward_score;
        """
        async with self.pool.acquire() as conn:
            await conn.execute(query, recommendation_id, reward, outcome_metric)
