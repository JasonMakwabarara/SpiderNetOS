"""
SpiderNet OS - Economic Reward Engine
Turns an hour of simulated spend and conversions into an RL reward that
trades profit off against ROAS and acquisition-cost risk.
"""

import math
from dataclasses import dataclass
from typing import Dict, Tuple


@dataclass
class EconomicOutcome:
    """Business outcome of one step of spend."""
    revenue: float
    costs: float
    gross_profit: float
    roas: float
    cac: float
    payback_period: float


class EconomicRewardEngine:
    """
    Reward = profit + ROAS bonus - CAC risk - spend volatility.

    Each component is normalised so the weights are comparable. The risk and
    payback terms are bounded: a step that spends but converts nobody has an
    infinite CAC, and an unbounded penalty there would swamp every other step
    in the episode.
    """

    # Budget per step the smoothness term measures volatility against.
    BASELINE_BUDGET = 100.0
    # Share of a customer's order value assumed to recur monthly.
    MONTHLY_REPEAT_RATE = 0.1

    def __init__(
        self,
        profit_weight: float = 1.0,
        roas_weight: float = 0.3,
        risk_weight: float = 0.2,
        target_roas: float = 3.0,
        max_cac: float = 30.0,
        margin_rate: float = 0.4,
    ):
        self.profit_weight = profit_weight
        self.roas_weight = roas_weight
        self.risk_weight = risk_weight
        self.target_roas = target_roas
        self.max_cac = max_cac
        self.margin_rate = margin_rate

    def compute_outcome(
        self,
        conversions: int,
        total_spend: float,
        avg_order_value: float = 50.0,
    ) -> EconomicOutcome:
        revenue = conversions * avg_order_value
        gross_profit = revenue * self.margin_rate - total_spend
        roas = revenue / total_spend if total_spend > 0 else 0.0

        no_sale_cac = math.inf if total_spend > 0 else 0.0
        cac = total_spend / conversions if conversions > 0 else no_sale_cac

        monthly_value = avg_order_value * self.MONTHLY_REPEAT_RATE * self.margin_rate
        payback_period = cac / monthly_value if monthly_value > 0 else math.inf

        return EconomicOutcome(
            revenue=revenue,
            costs=total_spend,
            gross_profit=gross_profit,
            roas=roas,
            cac=cac,
            payback_period=payback_period,
        )

    def compute_reward(
        self,
        outcome: EconomicOutcome,
        market_state: Dict,
        action: Dict,
    ) -> Tuple[float, Dict]:
        profit_component = outcome.gross_profit / 100.0

        roas_component = 0.0
        if outcome.roas > self.target_roas:
            roas_component = min(outcome.roas - self.target_roas, 2.0)

        risk_component = 0.0
        if outcome.cac > self.max_cac:
            risk_component = min((outcome.cac - self.max_cac) / self.max_cac, 1.0)

        budget = float(action.get('budget_allocation', self.BASELINE_BUDGET))
        spend_volatility = abs(budget - self.BASELINE_BUDGET) / self.BASELINE_BUDGET
        smoothness_component = -0.05 * min(spend_volatility, 1.0)

        total_reward = (
            self.profit_weight * profit_component
            + self.roas_weight * roas_component
            - self.risk_weight * risk_component
            + smoothness_component
        )

        components = {
            'profit': profit_component,
            'roas_bonus': roas_component,
            'risk_penalty': risk_component,
            'smoothness': smoothness_component,
            'total': total_reward,
        }
        return total_reward, components
