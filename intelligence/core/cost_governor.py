"""
SpiderNet OS v3.2 - Cost Governor
Hard Rule #4: CostGovernor overrides ALL execution
"""

import os
from dataclasses import dataclass
from datetime import datetime
from typing import Dict, List, Optional


@dataclass
class CostStatus:
    allowed: bool
    degraded: bool
    action: str
    daily_remaining: float
    monthly_remaining: float
    daily_limit: float
    monthly_limit: float
    alert_triggered: bool
    alert_threshold: float


class CostGovernor:
    """
    Enforces cost ceilings and model selection.
    Redis-backed spend tracking with projection sync.
    """

    def __init__(self, redis_client, db_pool):
        self.redis = redis_client
        self.db = db_pool
        self.model_costs = {
            'gpt-4': 0.03,
            'gpt-4o': 0.005,
            'gpt-4o-mini': 0.00015,
            'claude-3-opus': 0.015,
            'claude-3-sonnet': 0.003,
            'claude-3-haiku': 0.00025,
        }

    async def can_execute(
        self,
        tenant_id: str,
        estimated_cost: float = 0.0
    ) -> CostStatus:
        """Check if execution is allowed under budget constraints"""

        budget = await self._get_budget(tenant_id)
        today = datetime.utcnow().strftime('%Y-%m-%d')
        month = datetime.utcnow().strftime('%Y-%m')

        daily_spent = await self._get_daily_spend(tenant_id, today)
        monthly_spent = await self._get_monthly_spend(tenant_id, month)

        daily_remaining = budget['daily_limit'] - daily_spent
        monthly_remaining = budget['monthly_limit'] - monthly_spent

        allowed = daily_remaining > 0 and monthly_remaining > 0
        degraded = False
        action = 'allow'

        # Check if we should degrade instead of block
        if not allowed and budget['action_at_limit'] == 'degrade':
            allowed = True
            degraded = True
            action = 'degrade'

        # Alert threshold check
        alert_triggered = daily_spent >= budget['daily_limit'] * budget['alert_threshold']

        return CostStatus(
            allowed=allowed,
            degraded=degraded,
            action=action,
            daily_remaining=max(0, daily_remaining),
            monthly_remaining=max(0, monthly_remaining),
            daily_limit=budget['daily_limit'],
            monthly_limit=budget['monthly_limit'],
            alert_triggered=alert_triggered,
            alert_threshold=budget['alert_threshold'],
        )

    async def record_usage(
        self,
        tenant_id: str,
        resource_type: str,
        cost: float,
        metadata: Optional[Dict] = None
    ) -> None:
        """Record usage and emit event"""
        today = datetime.utcnow().strftime('%Y-%m-%d')
        month = datetime.utcnow().strftime('%Y-%m')

        # Atomic increment in Redis
        daily_key = f'cost:daily:{tenant_id}:{today}'
        monthly_key = f'cost:monthly:{tenant_id}:{month}'

        pipe = self.redis.pipeline()
        pipe.incrbyfloat(daily_key, cost)
        pipe.incrbyfloat(monthly_key, cost)
        pipe.expire(daily_key, 86400 * 30)  # 30 days
        pipe.expire(monthly_key, 86400 * 730)  # 2 years
        await pipe.execute()

        # Emit event for projection (via separate event store call)
        # This would call EventStore.append() in production

        # Check for budget exceeded
        status = await self.can_execute(tenant_id)
        if status.daily_remaining <= 0 or status.monthly_remaining <= 0:
            # Emit budget exceeded event
            pass  # Event emission handled by caller

    async def select_model(
        self,
        tenant_id: str,
        preferred_model: str,
        fallback_chain: List[str]
    ) -> str:
        """Select appropriate model based on budget constraints"""

        status = await self.can_execute(tenant_id)

        # If degraded mode, force cheapest model
        if status.degraded:
            return self._find_cheapest_available(fallback_chain)

        # Check if preferred model fits budget
        preferred_cost = self._estimate_model_cost(preferred_model)
        if preferred_cost <= status.daily_remaining:
            return preferred_model

        # Try fallback chain
        for fallback in fallback_chain:
            if self._estimate_model_cost(fallback) <= status.daily_remaining:
                return fallback

        # No affordable model
        raise RuntimeError('No affordable model available for tenant')

    async def _get_budget(self, tenant_id: str) -> Dict:
        """Get budget from projection or default"""
        # In production: query cost_budgets table via DB pool
        default_daily = float(os.getenv('COST_CEILING_DEFAULT', '10.00'))

        return {
            'daily_limit': default_daily,
            'monthly_limit': default_daily * 10,
            'alert_threshold': 0.80,
            'action_at_limit': 'block',
        }

    async def _get_daily_spend(self, tenant_id: str, date: str) -> float:
        """Get daily spend from Redis"""
        key = f'cost:daily:{tenant_id}:{date}'
        value = await self.redis.get(key)
        return float(value) if value else 0.0

    async def _get_monthly_spend(self, tenant_id: str, month: str) -> float:
        """Get monthly spend from Redis"""
        key = f'cost:monthly:{tenant_id}:{month}'
        value = await self.redis.get(key)
        return float(value) if value else 0.0

    def _estimate_model_cost(self, model: str) -> float:
        """Estimate cost per 1K tokens for model"""
        return self.model_costs.get(model, 0.01)

    def _find_cheapest_available(self, models: List[str]) -> str:
        """Find cheapest model from list"""
        sorted_models = sorted(models, key=lambda m: self.model_costs.get(m, float('inf')))
        return sorted_models[0] if sorted_models else 'gpt-4o-mini'
