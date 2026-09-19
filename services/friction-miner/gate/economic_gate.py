"""
SpiderNet OS - Economic Opportunity Gating Layer
Prevents opportunity overproduction via bounded rationality filters
Theory: Herbert Simon (bounded rationality) + Coase (transaction cost)
"""

from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import Dict, List, Optional, Tuple

import numpy as np


@dataclass
class Opportunity:
    """Standardized opportunity object"""
    id: str
    friction_sources: List[str]
    friction_score: float
    demand_proxy: float
    opportunity_score: float
    estimated_value: float
    cost_to_fix: float
    confidence: float
    suggested_solution: str
    required_agents: List[str]
    roi_estimate: float
    timestamp: datetime
    tenant_id: str
    metadata: Optional[Dict] = None


@dataclass
class GateResult:
    """Result of gating decision"""
    approved: bool
    opportunity: Opportunity
    reason: str
    filtered_at: datetime
    projected_profit: float


class EconomicGate:
    """
    Economic gating layer for opportunity filtering.

    Prevents:
    - Low-value opportunity flood
    - MetaPlanner overload
    - Compute cost explosion

    Filters:
    1. Score threshold (> 0.7)
    2. Cost-benefit (> 2× ROI)
    3. System load (< 0.9 utilization)
    4. Rate limiting (< 10/min)
    5. Duplicate detection (< 0.9 similarity)
    """

    def __init__(
        self,
        redis_client=None,
        score_threshold: float = 0.7,
        min_roi_ratio: float = 2.0,
        max_gpu_utilization: float = 0.9,
        max_queue_depth: int = 100,
        max_opportunities_per_minute: int = 10,
        similarity_threshold: float = 0.9,
        vector_store=None
    ):
        self.redis = redis_client
        self.vector_store = vector_store

        # Gating thresholds
        self.score_threshold = score_threshold
        self.min_roi_ratio = min_roi_ratio
        self.max_gpu_utilization = max_gpu_utilization
        self.max_queue_depth = max_queue_depth
        self.max_opportunities_per_minute = max_opportunities_per_minute
        self.similarity_threshold = similarity_threshold

        # Tracking
        self.recent_opportunities: Dict[str, List[datetime]] = {}
        self.approved_history: List[GateResult] = []
        self.filtered_history: List[Tuple[Opportunity, str]] = []

    async def evaluate(self, opportunity: Opportunity, system_state: Dict) -> GateResult:
        """
        Evaluate opportunity through all gating filters.

        Args:
            opportunity: Detected opportunity
            system_state: Current system metrics

        Returns:
            GateResult with approval decision
        """
        # Filter 1: Score threshold
        if opportunity.opportunity_score < self.score_threshold:
            return self._reject(opportunity, 'score_too_low', 0.0)

        # Filter 2: Cost-benefit analysis
        roi_ratio = opportunity.estimated_value / (opportunity.cost_to_fix + 1e-8)
        if roi_ratio < self.min_roi_ratio:
            return self._reject(opportunity, 'insufficient_roi', 0.0)

        # Filter 3: System load
        gpu_util = system_state.get('gpu_utilization', 0.0)
        queue_depth = system_state.get('queue_depth', 0)

        if gpu_util > self.max_gpu_utilization:
            return self._reject(opportunity, 'system_overloaded_gpu', 0.0)

        if queue_depth > self.max_queue_depth:
            return self._reject(opportunity, 'system_overloaded_queue', 0.0)

        # Filter 4: Rate limiting
        if not await self._check_rate_limit(opportunity.tenant_id):
            return self._reject(opportunity, 'rate_limit_exceeded', 0.0)

        # Filter 5: Duplicate detection (semantic similarity)
        is_duplicate, similar_op = await self._check_duplicate(opportunity)
        if is_duplicate:
            return self._reject(opportunity, f'duplicate_of_{similar_op}', 0.0)

        # Calculate projected profit
        projected_profit = opportunity.estimated_value - opportunity.cost_to_fix

        # Track approval
        result = GateResult(
            approved=True,
            opportunity=opportunity,
            reason='passed_all_filters',
            filtered_at=datetime.utcnow(),
            projected_profit=projected_profit
        )

        self.approved_history.append(result)
        await self._track_approval(opportunity)

        return result

    def _reject(self, opportunity: Opportunity, reason: str, profit: float) -> GateResult:
        """Create rejection result"""
        result = GateResult(
            approved=False,
            opportunity=opportunity,
            reason=reason,
            filtered_at=datetime.utcnow(),
            projected_profit=profit
        )

        self.filtered_history.append((opportunity, reason))

        # Trim history
        if len(self.filtered_history) > 10000:
            self.filtered_history = self.filtered_history[-10000:]

        return result

    async def _check_rate_limit(self, tenant_id: str) -> bool:
        """Check if tenant is within rate limit"""
        now = datetime.utcnow()
        window_start = now - timedelta(minutes=1)

        # Get recent opportunities for tenant
        if tenant_id not in self.recent_opportunities:
            self.recent_opportunities[tenant_id] = []

        # Filter to last minute
        self.recent_opportunities[tenant_id] = [
            t for t in self.recent_opportunities[tenant_id]
            if t > window_start
        ]

        # Check limit
        if len(self.recent_opportunities[tenant_id]) >= self.max_opportunities_per_minute:
            return False

        # Track this opportunity
        self.recent_opportunities[tenant_id].append(now)
        return True

    async def _check_duplicate(self, opportunity: Opportunity) -> Tuple[bool, Optional[str]]:
        """
        Check for semantically similar recent opportunities.

        Uses vector similarity search if vector_store available,
        otherwise falls back to metadata comparison.
        """
        if not self.vector_store:
            # Fallback: check recent approved opportunities
            for result in reversed(self.approved_history[-100:]):
                similar = self._compute_similarity(opportunity, result.opportunity)
                if similar > self.similarity_threshold:
                    return True, result.opportunity.id
            return False, None

        # Vector similarity search
        similar_ops = await self.vector_store.search_similar(
            query_embedding=opportunity.metadata.get('embedding', []),
            top_k=5,
            threshold=self.similarity_threshold
        )

        if similar_ops:
            return True, similar_ops[0]['id']

        return False, None

    def _compute_similarity(self, op1: Opportunity, op2: Opportunity) -> float:
        """Compute similarity between two opportunities"""
        # Friction source overlap
        friction_overlap = len(set(op1.friction_sources) & set(op2.friction_sources))
        friction_sim = friction_overlap / max(len(op1.friction_sources), len(op2.friction_sources), 1)

        # Required agent overlap
        agent_overlap = len(set(op1.required_agents) & set(op2.required_agents))
        agent_sim = agent_overlap / max(len(op1.required_agents), len(op2.required_agents), 1)

        # Score similarity
        score_sim = 1 - abs(op1.opportunity_score - op2.opportunity_score)

        # Weighted combination
        return 0.4 * friction_sim + 0.4 * agent_sim + 0.2 * score_sim

    async def _track_approval(self, opportunity: Opportunity):
        """Track approved opportunity for metrics"""
        if self.redis:
            key = f"friction:gated:{opportunity.tenant_id}"
            await self.redis.lpush(key, {
                'opportunity_id': opportunity.id,
                'score': opportunity.opportunity_score,
                'timestamp': datetime.utcnow().isoformat()
            })
            await self.redis.ltrim(key, 0, 999)  # Keep last 1000

    def get_gate_statistics(self, hours: int = 24) -> Dict:
        """Get gating statistics for analysis"""
        cutoff = datetime.utcnow() - timedelta(hours=hours)

        # Filter to time window
        approved_recent = [
            r for r in self.approved_history
            if r.filtered_at > cutoff
        ]
        filtered_recent = [
            (o, r) for o, r in self.filtered_history
            if o.timestamp > cutoff
        ]

        # Compute pass rate
        total = len(approved_recent) + len(filtered_recent)
        pass_rate = len(approved_recent) / total if total > 0 else 0

        # Filter breakdown
        filter_reasons = {}
        for _, reason in filtered_recent:
            filter_reasons[reason] = filter_reasons.get(reason, 0) + 1

        # Value saved by filtering
        filtered_value = sum(
            o.cost_to_fix for o, _ in filtered_recent
        )

        return {
            'total_evaluated': total,
            'approved_count': len(approved_recent),
            'filtered_count': len(filtered_recent),
            'pass_rate': pass_rate,
            'filter_breakdown': filter_reasons,
            'value_saved_by_filtering': filtered_value,
            'avg_approved_score': np.mean([r.opportunity.opportunity_score for r in approved_recent]) if approved_recent else 0,
            'avg_filtered_score': np.mean([o.opportunity_score for o, _ in filtered_recent]) if filtered_recent else 0
        }

    def adjust_thresholds(self, target_pass_rate: float = 0.25):
        """
        Auto-adjust thresholds based on observed pass rate.

        Target: Pass 20-30% of opportunities (quality over quantity)
        """
        stats = self.get_gate_statistics(hours=1)
        current_pass_rate = stats['pass_rate']

        # Adjust score threshold
        if current_pass_rate > target_pass_rate + 0.05:
            # Too many passing, raise threshold
            self.score_threshold = min(self.score_threshold + 0.05, 0.95)
        elif current_pass_rate < target_pass_rate - 0.05:
            # Too few passing, lower threshold
            self.score_threshold = max(self.score_threshold - 0.05, 0.5)

        return {
            'old_pass_rate': current_pass_rate,
            'new_threshold': self.score_threshold
        }


class BoundedExploration:
    """
    Bounded exploration for CPL with cost constraints.

    Theory: Upper Confidence Bound (UCB) with budget awareness
    """

    def __init__(
        self,
        base_exploration_coef: float = 1.0,
        exploration_budget_ratio: float = 0.1,
        max_regret_multiplier: float = 2.0
    ):
        self.base_coef = base_exploration_coef
        self.budget_ratio = exploration_budget_ratio
        self.max_regret = max_regret_multiplier

        # Track exploration spend
        self.exploration_spend = 0.0
        self.total_budget = 0.0
        self.cumulative_regret = 0.0

    def compute_exploration_bonus(
        self,
        action_value: float,
        action_count: int,
        total_actions: int,
        remaining_budget: float
    ) -> float:
        """
        Compute cost-constrained UCB exploration bonus.

        Formula: c * sqrt(ln N / n_a) * (remaining_budget / total_budget)
        """
        # Standard UCB term
        if action_count == 0:
            ucb_term = float('inf')  # Force exploration of untried actions
        else:
            ucb_term = np.sqrt(np.log(total_actions + 1) / action_count)

        # Budget scaling (exploration decreases as budget depletes)
        if self.total_budget > 0:
            budget_scale = remaining_budget / self.total_budget
        else:
            budget_scale = 1.0

        # Regret check (if cumulative regret too high, stop exploring)
        if self.cumulative_regret > self.max_regret * action_value:
            return 0.0  # Force exploitation

        # Exploration budget check
        if self.exploration_spend > self.budget_ratio * self.total_budget:
            return 0.0  # Exploration budget exhausted

        return self.base_coef * ucb_term * budget_scale

    def record_exploration(self, cost: float, reward: float, expected_reward: float):
        """Record exploration outcome"""
        self.exploration_spend += cost
        regret = expected_reward - reward
        self.cumulative_regret += max(0, regret)

    def should_explore(self, remaining_budget: float) -> bool:
        """Check if exploration is still allowed"""
        # Budget constraint
        if self.exploration_spend > self.budget_ratio * self.total_budget:
            return False

        # Regret constraint
        if self.cumulative_regret > 0:
            avg_regret = self.cumulative_regret / max(self.exploration_spend, 1)
            if avg_regret > self.max_regret:
                return False

        return True
