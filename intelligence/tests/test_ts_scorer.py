"""
Tests for intelligence/atlas/ts.py — Transformation Score computation
"""
import unittest
from unittest.mock import patch, MagicMock

from atlas.ts import TransformationScorer, TSFeatures


class TestTSScorerComputation(unittest.TestCase):

    def setUp(self):
        self.scorer = TransformationScorer()

    def test_perfect_features_yield_high_ts(self):
        features = TSFeatures(
            calculated_at_null_count=0,
            shadow_diff_open=0,
            usage_api_2xx_rate=1.0,
            aggregate_job_success_rate=1.0,
            api_p95_ms=200.0,
            atlas_loop_p95_ms=800.0,
            ux_ts_scores=[0.90, 0.92, 0.88],
            ux_observed_rewards=[0.85, 0.80],
            trust_floor_breaches=0,
            cutover_rollback_invoked=False,
            unexpected_410s=0,
            consumers_migrated=5,
            consumers_total=5,
        )
        result = self.scorer.compute(features)
        self.assertGreaterEqual(result.ts, 0.85, f"Perfect features should hit D+7 target. Got {result.ts}")
        self.assertTrue(result.meets_d7_target)

    def test_null_calculated_at_reduces_data_integrity(self):
        features = TSFeatures(calculated_at_null_count=10, shadow_diff_open=0)
        result = self.scorer.compute(features)
        self.assertLess(result.data_integrity, 1.0)

    def test_trust_floor_breach_zeros_ux_transformation(self):
        features = TSFeatures(
            trust_floor_breaches=1,
            ux_ts_scores=[0.90],
            ux_observed_rewards=[0.85],
        )
        result = self.scorer.compute(features)
        self.assertEqual(result.ux_transformation, 0.0)

    def test_api_latency_within_slo_yields_full_score(self):
        features = TSFeatures(api_p95_ms=350.0, atlas_loop_p95_ms=1200.0)
        result = self.scorer.compute(features)
        self.assertAlmostEqual(result.latency_budget, 1.0, places=1)

    def test_api_latency_over_slo_decays_score(self):
        features = TSFeatures(api_p95_ms=800.0, atlas_loop_p95_ms=3000.0)
        result = self.scorer.compute(features)
        self.assertLess(result.latency_budget, 1.0)

    def test_rollback_invoked_penalises_cutover_cleanliness(self):
        features = TSFeatures(cutover_rollback_invoked=True)
        result = self.scorer.compute(features)
        self.assertLessEqual(result.cutover_cleanliness, 0.5)

    def test_partial_consumer_migration(self):
        features = TSFeatures(consumers_migrated=3, consumers_total=5)
        result = self.scorer.compute(features)
        self.assertAlmostEqual(result.consumer_migration, 0.6)

    def test_ts_bounded_between_0_and_1(self):
        bad = TSFeatures(
            calculated_at_null_count=100,
            shadow_diff_open=100,
            usage_api_2xx_rate=0.0,
            trust_floor_breaches=5,
            cutover_rollback_invoked=True,
        )
        result = self.scorer.compute(bad)
        self.assertGreaterEqual(result.ts, 0.0)
        self.assertLessEqual(result.ts, 1.0)

    def test_meets_d30_target_requires_higher_ts(self):
        features = TSFeatures(
            calculated_at_null_count=0,
            shadow_diff_open=0,
            usage_api_2xx_rate=0.999,
            aggregate_job_success_rate=0.999,
            api_p95_ms=150.0,
            atlas_loop_p95_ms=600.0,
            ux_ts_scores=[0.93, 0.91, 0.94],
            ux_observed_rewards=[0.88, 0.90, 0.87],
            trust_floor_breaches=0,
            cutover_rollback_invoked=False,
            unexpected_410s=0,
            consumers_migrated=5,
            consumers_total=5,
        )
        result = self.scorer.compute(features)
        self.assertGreaterEqual(result.ts, 0.92, f"D+30 target not met. Got {result.ts}")
        self.assertTrue(result.meets_d30_target)


if __name__ == "__main__":
    unittest.main()
