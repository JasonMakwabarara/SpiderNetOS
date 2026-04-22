"""
Atlas Thompson Sampling Bandit (§11.11)

Provides the core bandit decision-making logic used by the Python side of the
Atlas loop (e.g. batch candidate seeding, offline evaluation).

The PHP AtlasCopyController handles online (request-time) selection directly
against Postgres.  This module handles:
  - Offline batch evaluation / simulation
  - Cold-start prior seeding (cohort + embedding priors)
  - Entropy monitoring
  - Explorability audits

Usage:
    from intelligence.atlas.bandit import ThompsonBandit
"""

from __future__ import annotations

import math
import random
from dataclasses import dataclass, field
from typing import Any


@dataclass
class BanditArm:
    """Represents a copy variant arm."""
    variant_id:    str
    surface:       str
    predicted_ts:  float
    trust_score:   float
    impressions:   int   = 0
    clicks:        int   = 0
    actions:       int   = 0
    conversions:   int   = 0
    alpha:         float = 1.0    # Beta distribution alpha (successes)
    beta:          float = 1.0    # Beta distribution beta  (failures)
    avg_reward:    float = 0.0


@dataclass
class SelectionResult:
    arm:           BanditArm
    theta:         float
    method:        str            # "thompson" | "epsilon_greedy" | "floor_boosted"
    entropy:       float          # Posterior entropy across all arms at selection time


class ThompsonBandit:
    """
    Thompson Sampling bandit with:
      - Temperature scaling  (§11.11)
      - Minimum impression floor enforcement  (§11.11)
      - Cohort prior smoothing  (§11.11)
      - Entropy monitoring  (§11.11)
      - Trust-floor and TS-gate enforcement  (§4.3.2)
    """

    TRUST_FLOOR:  float = 0.80
    TS_FLOOR:     float = 0.72

    def __init__(
        self,
        temperature:    float = 1.0,
        floor:          int   = 50,
        algo:           str   = "thompson",
    ) -> None:
        # Clamp temperature to safe range
        self.temperature = max(0.5, min(1.2, temperature))
        self.floor       = floor
        self.algo        = algo

    # -----------------------------------------------------------------------
    # Selection
    # -----------------------------------------------------------------------

    def select(self, arms: list[BanditArm], surface: str) -> SelectionResult | None:
        """
        Select the best arm for a surface.
        Returns None if no arm passes the trust / TS gates.
        """
        eligible = [
            a for a in arms
            if a.trust_score >= self.TRUST_FLOOR and a.predicted_ts >= self.TS_FLOOR
        ]
        if not eligible:
            return None

        prior_alpha, prior_beta = self._cohort_priors(eligible)

        scored: list[tuple[BanditArm, float, str]] = []

        for arm in eligible:
            a = arm.alpha + prior_alpha
            b = arm.beta  + prior_beta

            if self.algo == "thompson":
                theta  = self._beta_sample(a, b)
                method = "thompson"
            else:
                # ε-greedy
                eps    = max(0.05, 1.0 / math.sqrt(max(1, arm.impressions)))
                if random.random() < eps:
                    theta  = random.random()
                    method = "epsilon_greedy"
                else:
                    theta  = arm.avg_reward
                    method = "epsilon_greedy"

            # Floor enforcement
            if arm.impressions < self.floor:
                theta  = max(theta, random.uniform(0.5, 1.0))
                method = "floor_boosted"

            scored.append((arm, theta, method))

        if not scored:
            return None

        scored.sort(key=lambda x: x[1], reverse=True)
        best_arm, best_theta, best_method = scored[0]

        entropy = self._posterior_entropy(eligible)
        return SelectionResult(
            arm=best_arm,
            theta=best_theta,
            method=best_method,
            entropy=entropy,
        )

    # -----------------------------------------------------------------------
    # Reward update
    # -----------------------------------------------------------------------

    def update(
        self,
        arm:            BanditArm,
        kind:           str,    # click | action | conversion
        predicted_ts:   float,
    ) -> float:
        """
        Update arm counters + Beta parameters; return blended reward.
        Blended reward = 0.6 * predicted_ts + 0.4 * observed_signal  (§4.3.9)
        """
        observed = {"click": 0.4, "action": 0.7, "conversion": 1.0}.get(kind, 0.0)
        reward   = 0.6 * predicted_ts + 0.4 * observed

        if kind == "click":
            arm.clicks += 1
        elif kind == "action":
            arm.actions += 1
        elif kind == "conversion":
            arm.conversions += 1

        # Success / failure update
        if kind in ("action", "conversion"):
            arm.alpha += reward
        else:
            arm.beta += (1 - reward)

        # Rolling average reward
        total_events = arm.clicks + arm.actions + arm.conversions
        if total_events > 0:
            arm.avg_reward = (arm.avg_reward * (total_events - 1) + reward) / total_events

        return reward

    # -----------------------------------------------------------------------
    # Entropy monitoring
    # -----------------------------------------------------------------------

    def posterior_entropy(self, arms: list[BanditArm]) -> float:
        return self._posterior_entropy(arms)

    def is_collapsed(self, arms: list[BanditArm], threshold: float = 0.3) -> bool:
        """Return True when entropy has dropped below the collapse threshold (§11.11)."""
        return self._posterior_entropy(arms) < threshold

    # -----------------------------------------------------------------------
    # Cold-start seeding
    # -----------------------------------------------------------------------

    @staticmethod
    def seed_from_cohort(arm: BanditArm, cohort_ctr: float, n_pseudo: int = 10) -> None:
        """
        Initialise Beta priors from historical cohort CTR (§11.11).
        n_pseudo = number of pseudo-observations to inject.
        """
        arm.alpha = 1.0 + cohort_ctr * n_pseudo
        arm.beta  = 1.0 + (1.0 - cohort_ctr) * n_pseudo

    # -----------------------------------------------------------------------
    # Private helpers
    # -----------------------------------------------------------------------

    def _beta_sample(self, alpha: float, beta: float) -> float:
        """Sample from Beta(α, β) with temperature scaling."""
        a = max(0.01, alpha / self.temperature)
        b = max(0.01, beta  / self.temperature)
        # Use stdlib random.betavariate
        return random.betavariate(a, b)

    def _cohort_priors(self, arms: list[BanditArm]) -> tuple[float, float]:
        """
        Compute global cohort-level smoothing prior (§11.11 hierarchical smoothing).
        Returns (alpha_prior, beta_prior) to add to each arm's parameters.
        """
        total_impressions = sum(a.impressions for a in arms)
        if total_impressions == 0:
            return 1.0, 1.0
        total_clicks = sum(a.clicks for a in arms)
        ctr   = total_clicks / total_impressions
        scale = 10.0            # pseudo-count magnitude
        return ctr * scale, (1 - ctr) * scale

    def _posterior_entropy(self, arms: list[BanditArm]) -> float:
        """
        Approximate entropy of the distribution over arms by computing the
        entropy of mean-posterior success probabilities.
        """
        if not arms:
            return 0.0
        means = [a.alpha / max(1e-9, a.alpha + a.beta) for a in arms]
        total = sum(means) or 1e-9
        probs = [m / total for m in means]
        return -sum(p * math.log2(p + 1e-12) for p in probs)
