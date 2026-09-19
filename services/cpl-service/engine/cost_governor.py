"""
SpiderNet OS - Cost Governor with Lagrangian Constraints
Profit protection through constrained reinforcement learning
Based on Todorov (2006) optimal control theory
"""

import asyncio
from dataclasses import dataclass
from datetime import datetime
from typing import Dict, Optional, Tuple


@dataclass
class BudgetStatus:
    """Current budget status for a tenant"""
    tenant_id: str
    budget_limit: float
    spent_today: float
    remaining: float
    utilization_rate: float
    projected_end_of_day: float
    status: str  # 'healthy', 'warning', 'critical', 'exceeded'


@dataclass
class CostConstraint:
    """Lagrangian cost constraint configuration"""
    budget: float
    lambda_cost: float
    violation_history: list
    adaptive_rate: float = 0.01


class CostGovernor:
    """
    Hierarchical cost governor with Lagrangian constraint optimization.

    Theory:
        maximize E[reward]
        subject to E[cost] ≤ budget

    Implementation:
        R_final = R_base - λ_cost * max(0, cost - budget)
        λ ← λ + α * violation  (adaptive update)

    Hard Rule #4: CostGovernor overrides ALL execution
    """

    def __init__(
        self,
        redis_client=None,
        db_pool=None,
        default_budget: float = 50.0,
        safety_margin: float = 0.1  # 10% headroom
    ):
        self.redis = redis_client
        self.db = db_pool
        self.default_budget = default_budget
        self.safety_margin = safety_margin

        # Lagrangian multipliers per tenant (adaptive)
        self.lambdas: Dict[str, CostConstraint] = {}

        # GPU cost tracking ($0.40/hour for RTX 5090)
        self.gpu_hourly_rate = 0.40
        self.token_cost_rate = 0.0008  # per 1K tokens

    async def check_budget(self, tenant_id: str, estimated_cost: float) -> Tuple[bool, Dict]:
        """
        Check if action is within budget (Hard Rule #4 gate).

        Args:
            tenant_id: Tenant identifier
            estimated_cost: Predicted cost of action

        Returns:
            (allowed, details)
        """
        # Get current spend
        current_spend = await self._get_current_spend(tenant_id)
        budget = await self._get_tenant_budget(tenant_id)

        # Apply safety margin
        effective_budget = budget * (1 - self.safety_margin)

        # Check if exceeded
        projected = current_spend + estimated_cost

        if projected > effective_budget:
            return False, {
                'allowed': False,
                'reason': 'budget_exceeded',
                'current_spend': current_spend,
                'estimated_cost': estimated_cost,
                'budget': budget,
                'projected': projected,
                'violation': projected - effective_budget
            }

        # Check warning threshold (80%)
        warning_threshold = effective_budget * 0.8
        status = 'healthy' if projected < warning_threshold else 'warning'

        return True, {
            'allowed': True,
            'status': status,
            'current_spend': current_spend,
            'remaining': effective_budget - current_spend,
            'projected': projected
        }

    def compute_lagrangian_penalty(
        self,
        tenant_id: str,
        reward: float,
        cost: float,
        budget: float
    ) -> Tuple[float, float]:
        """
        Apply Lagrangian constraint penalty to reward.

        Formula: R_final = R - λ * max(0, cost - budget)

        Args:
            tenant_id: Tenant identifier
            reward: Base reward
            cost: Actual cost incurred
            budget: Budget limit

        Returns:
            (penalized_reward, violation)
        """
        # Get or create Lagrangian constraint
        if tenant_id not in self.lambdas:
            self.lambdas[tenant_id] = CostConstraint(
                budget=budget,
                lambda_cost=0.1,
                violation_history=[]
            )

        constraint = self.lambdas[tenant_id]

        # Compute violation
        violation = max(0.0, cost - budget)

        # Apply penalty
        penalized_reward = reward - constraint.lambda_cost * violation

        # Track violation
        constraint.violation_history.append({
            'timestamp': datetime.utcnow().isoformat(),
            'violation': violation,
            'lambda': constraint.lambda_cost
        })

        # Trim history
        if len(constraint.violation_history) > 1000:
            constraint.violation_history = constraint.violation_history[-1000:]

        return penalized_reward, violation

    def update_lambda(self, tenant_id: str, violation: float):
        """
        Adaptively update Lagrangian multiplier.

        Formula: λ ← λ + α * violation
        """
        if tenant_id not in self.lambdas:
            return

        constraint = self.lambdas[tenant_id]

        # Gradient ascent on constraint
        constraint.lambda_cost += constraint.adaptive_rate * violation

        # Clamp to prevent runaway
        constraint.lambda_cost = min(constraint.lambda_cost, 10.0)

        return constraint.lambda_cost

    async def record_cost(
        self,
        tenant_id: str,
        cost_type: str,
        amount: float,
        metadata: Optional[Dict] = None
    ):
        """Record actual cost for tracking"""
        # Store in Redis for real-time tracking
        if self.redis:
            key = f"spidernet:cost:{tenant_id}:{datetime.utcnow().strftime('%Y-%m-%d')}"
            await self.redis.hincrbyfloat(key, cost_type, amount)

            # Publish cost event
            await self.redis.publish("spidernet:cost_events", {
                'tenant_id': tenant_id,
                'cost_type': cost_type,
                'amount': amount,
                'timestamp': datetime.utcnow().isoformat(),
                'metadata': metadata or {}
            })

        # Store in PostgreSQL for audit
        if self.db:
            await self.db.execute(
                """
                INSERT INTO cost_events
                (tenant_id, cost_type, amount, metadata, recorded_at)
                VALUES ($1, $2, $3, $4, $5)
                """,
                tenant_id, cost_type, amount,
                metadata or {}, datetime.utcnow()
            )

    async def _get_current_spend(self, tenant_id: str) -> float:
        """Get current day's spend for tenant"""
        if not self.redis:
            return 0.0

        key = f"spidernet:cost:{tenant_id}:{datetime.utcnow().strftime('%Y-%m-%d')}"
        costs = await self.redis.hgetall(key)

        total = sum(float(v) for v in costs.values())
        return total

    async def _get_tenant_budget(self, tenant_id: str) -> float:
        """Get budget limit for tenant"""
        # Default or from database
        return self.default_budget

    def calculate_gpu_cost(
        self,
        gpu_seconds: Dict[str, float],
        token_counts: Dict[str, int]
    ) -> float:
        """
        Calculate total cost from GPU usage.

        Args:
            gpu_seconds: {'gpu_0': 120.5, 'gpu_1': 45.2}
            token_counts: {'gemma4': 5000, 'qwen3': 12000}

        Returns:
            Total cost in USD
        """
        # GPU time cost
        total_gpu_hours = sum(gpu_seconds.values()) / 3600
        gpu_cost = total_gpu_hours * self.gpu_hourly_rate

        # Token cost
        total_tokens = sum(token_counts.values())
        token_cost = (total_tokens / 1000) * self.token_cost_rate

        return gpu_cost + token_cost

    async def get_budget_status(self, tenant_id: str) -> BudgetStatus:
        """Get complete budget status for tenant"""
        current_spend = await self._get_current_spend(tenant_id)
        budget = await self._get_tenant_budget(tenant_id)
        effective_budget = budget * (1 - self.safety_margin)

        remaining = effective_budget - current_spend
        utilization = current_spend / effective_budget if effective_budget > 0 else 0

        # Determine status
        if remaining <= 0:
            status = 'exceeded'
        elif utilization > 0.95:
            status = 'critical'
        elif utilization > 0.8:
            status = 'warning'
        else:
            status = 'healthy'

        # Project end-of-day spend
        hours_elapsed = datetime.utcnow().hour + datetime.utcnow().minute / 60
        if hours_elapsed > 0:
            hourly_rate = current_spend / hours_elapsed
            projected = hourly_rate * 24
        else:
            projected = current_spend

        return BudgetStatus(
            tenant_id=tenant_id,
            budget_limit=budget,
            spent_today=current_spend,
            remaining=remaining,
            utilization_rate=utilization,
            projected_end_of_day=projected,
            status=status
        )

    def get_constraint_summary(self, tenant_id: str) -> Dict:
        """Get Lagrangian constraint summary"""
        if tenant_id not in self.lambdas:
            return {'lambda_cost': 0.1, 'violations_24h': 0}

        constraint = self.lambdas[tenant_id]

        # Count recent violations
        recent = [
            v for v in constraint.violation_history
            if (datetime.utcnow() - datetime.fromisoformat(v['timestamp'])).days < 1
        ]

        return {
            'lambda_cost': constraint.lambda_cost,
            'violations_24h': len(recent),
            'avg_violation': sum(v['violation'] for v in recent) / len(recent) if recent else 0,
            'budget': constraint.budget
        }


class BudgetEnforcer:
    """
    Async budget enforcement for real-time cost control.
    Wraps CostGovernor with async event handling.
    """

    def __init__(self, cost_governor: CostGovernor):
        self.governor = cost_governor
        self._lock = asyncio.Lock()

    async def execute_with_budget_check(
        self,
        tenant_id: str,
        estimated_cost: float,
        action_func,
        *args,
        **kwargs
    ) -> Tuple[bool, any]:
        """
        Execute action with budget pre-check and post-recording.

        Args:
            tenant_id: Tenant identifier
            estimated_cost: Estimated cost before execution
            action_func: Async function to execute
            args, kwargs: Arguments for action_func

        Returns:
            (success, result_or_error)
        """
        async with self._lock:
            # Pre-check budget
            allowed, details = await self.governor.check_budget(tenant_id, estimated_cost)

            if not allowed:
                return False, {
                    'error': 'budget_exceeded',
                    'details': details
                }

            # Execute action
            try:
                result = await action_func(*args, **kwargs)

                # Record actual cost
                actual_cost = result.get('cost', estimated_cost)
                await self.governor.record_cost(
                    tenant_id,
                    'execution',
                    actual_cost,
                    {'estimated': estimated_cost, 'action': action_func.__name__}
                )

                return True, result

            except Exception as e:
                return False, {'error': str(e)}
