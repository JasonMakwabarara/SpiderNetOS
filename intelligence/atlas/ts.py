"""
Atlas Transformation Score (TS) Computation — Feature Store Writer (§8.3, T12)

Computes the composite TS for the Blocker-A fix scenario and writes signals to
the Atlas feature store (Redis hash with TTL).

TS formula (§8.3):
  TS = 0.30 * data_integrity
     + 0.25 * ux_transformation
     + 0.20 * availability
     + 0.15 * latency_budget
     + 0.05 * cutover_cleanliness
     + 0.05 * consumer_migration

Usage:
    from intelligence.atlas.ts import TransformationScorer, TSFeatures

    features = TSFeatures(
        calculated_at_null_count=0,
        shadow_diff_open=0,
        usage_api_2xx_rate=0.999,
        aggregate_job_success_rate=1.0,
        api_p95_ms=280.0,
        ux_ts_scores=[0.82, 0.79, 0.85],
        ux_observed_rewards=[0.74, 0.81],
        trust_floor_breaches=0,
        cutover_rollback_invoked=False,
        unexpected_410s=0,
        consumers_migrated=5,
        consumers_total=5,
    )

    scorer = TransformationScorer()
    result = scorer.compute(features)
    scorer.write_to_store(result)
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass, field, asdict
from typing import Optional

import redis as _redis_pkg

from config import REDIS_URL

# ---------------------------------------------------------------------------
# Redis feature store
# ---------------------------------------------------------------------------

_STORE_TTL = 86_400 * 7          # 7 days
_KEY_PREFIX = "atlas.features.usage_v2"

try:
    _redis = _redis_pkg.from_url(REDIS_URL, decode_responses=True)
except Exception:
    _redis = None   # type: ignore[assignment]

# ---------------------------------------------------------------------------
# Data classes
# ---------------------------------------------------------------------------

@dataclass
class TSFeatures:
    """Input signals for the TS computation."""

    # data_integrity
    calculated_at_null_count: int   = 0     # must be 0 for full score
    shadow_diff_open:          int   = 0     # unresolved diffs in last 24h

    # availability
    usage_api_2xx_rate:        float = 1.0   # [0,1]
    aggregate_job_success_rate: float = 1.0   # [0,1]

    # latency_budget
    api_p95_ms:                float = 0.0   # p95 latency of /usage/* endpoints
    atlas_loop_p95_ms:          float = 0.0  # end-to-end Atlas loop p95

    # ux_transformation (blend of RT predicted + observed bandit reward)
    ux_ts_scores:               list[float] = field(default_factory=list)
    ux_observed_rewards:        list[float] = field(default_factory=list)
    trust_floor_breaches:       int   = 0

    # cutover_cleanliness
    cutover_rollback_invoked:   bool  = False
    unexpected_410s:            int   = 0    # /usage/* 410s after D+2

    # consumer_migration
    consumers_migrated:         int   = 0
    consumers_total:            int   = 1    # avoid div-by-zero


@dataclass
class TSResult:
    ts:                    float
    data_integrity:        float
    ux_transformation:     float
    availability:          float
    latency_budget:        float
    cutover_cleanliness:   float
    consumer_migration:    float
    computed_at:           float = field(default_factory=time.time)
    meets_d7_target:       bool  = False   # TS >= 0.85
    meets_d30_target:      bool  = False   # TS >= 0.92


# ---------------------------------------------------------------------------
# Scorer
# ---------------------------------------------------------------------------

class TransformationScorer:

    # TS sub-score weights (§8.3)
    WEIGHTS = {
        "data_integrity":      0.30,
        "ux_transformation":   0.25,
        "availability":        0.20,
        "latency_budget":      0.15,
        "cutover_cleanliness": 0.05,
        "consumer_migration":  0.05,
    }

    def compute(self, f: TSFeatures) -> TSResult:
        data_int   = self._data_integrity(f)
        ux_trans   = self._ux_transformation(f)
        avail      = self._availability(f)
        latency    = self._latency_budget(f)
        cutover    = self._cutover_cleanliness(f)
        consumers  = self._consumer_migration(f)

        ts = (
            data_int  * self.WEIGHTS["data_integrity"]
            + ux_trans * self.WEIGHTS["ux_transformation"]
            + avail    * self.WEIGHTS["availability"]
            + latency  * self.WEIGHTS["latency_budget"]
            + cutover  * self.WEIGHTS["cutover_cleanliness"]
            + consumers * self.WEIGHTS["consumer_migration"]
        )
        ts = round(min(1.0, max(0.0, ts)), 4)

        result = TSResult(
            ts=ts,
            data_integrity=round(data_int, 4),
            ux_transformation=round(ux_trans, 4),
            availability=round(avail, 4),
            latency_budget=round(latency, 4),
            cutover_cleanliness=round(cutover, 4),
            consumer_migration=round(consumers, 4),
        )
        result.meets_d7_target  = ts >= 0.85
        result.meets_d30_target = ts >= 0.92
        return result

    def write_to_store(self, result: TSResult, extra_context: Optional[dict] = None) -> None:
        """Write TS signals to Redis feature store for Atlas RL loop."""
        if _redis is None:
            return

        data = asdict(result)
        if extra_context:
            data.update(extra_context)

        key = f"{_KEY_PREFIX}.ts"
        try:
            _redis.hset(key, mapping={k: str(v) for k, v in data.items()})
            _redis.expire(key, _STORE_TTL)
        except Exception:
            pass

    # -----------------------------------------------------------------------
    # Sub-scorers
    # -----------------------------------------------------------------------

    def _data_integrity(self, f: TSFeatures) -> float:
        """
        Full score when:
          - calculated_at NULL count = 0
          - Zero open shadow diffs
        Decays linearly with drift count.
        """
        null_penalty = min(1.0, f.calculated_at_null_count * 0.05)
        diff_penalty = min(1.0, f.shadow_diff_open * 0.1)
        return max(0.0, 1.0 - null_penalty - diff_penalty)

    def _ux_transformation(self, f: TSFeatures) -> float:
        """
        Blended RT (predicted TS) + observed bandit reward (§4.3.9).
        Trust-floor breach zeroes the sub-score (§8.3 hard gate).
        """
        if f.trust_floor_breaches > 0:
            return 0.0

        predicted_avg = (
            sum(f.ux_ts_scores) / len(f.ux_ts_scores)
            if f.ux_ts_scores else 0.0
        )
        observed_avg = (
            sum(f.ux_observed_rewards) / len(f.ux_observed_rewards)
            if f.ux_observed_rewards else 0.0
        )
        # Dual-scoring blend
        return 0.6 * predicted_avg + 0.4 * observed_avg

    def _availability(self, f: TSFeatures) -> float:
        return (f.usage_api_2xx_rate + f.aggregate_job_success_rate) / 2.0

    def _latency_budget(self, f: TSFeatures) -> float:
        """
        SLOs:  /usage/* p95 < 400 ms, Atlas loop p95 < 1500 ms.
        Score = 1.0 if both within SLO, linear decay outside.
        """
        api_slo    = 400.0
        atlas_slo  = 1500.0

        api_score   = 1.0 if f.api_p95_ms <= api_slo else max(0.0, 1.0 - (f.api_p95_ms - api_slo) / api_slo)
        atlas_score = 1.0 if f.atlas_loop_p95_ms <= atlas_slo else max(0.0, 1.0 - (f.atlas_loop_p95_ms - atlas_slo) / atlas_slo)

        # If no latency data provided, treat as unknown (score 0.5)
        if f.api_p95_ms == 0:
            api_score = 0.5
        if f.atlas_loop_p95_ms == 0:
            atlas_score = 0.5

        return (api_score + atlas_score) / 2.0

    def _cutover_cleanliness(self, f: TSFeatures) -> float:
        score = 1.0
        if f.cutover_rollback_invoked:
            score -= 0.5
        score -= min(0.5, f.unexpected_410s * 0.05)
        return max(0.0, score)

    def _consumer_migration(self, f: TSFeatures) -> float:
        if f.consumers_total == 0:
            return 1.0
        return f.consumers_migrated / f.consumers_total
