"""
Tests for intelligence/atlas/feature_flag.py

Verifies:
  - Default resolution
  - Redis override
  - Per-tenant override wins over global
  - Type coercion for float/int flags
  - Cache TTL behaviour (in-process cache)
  - Python/PHP parity (documented contract)
"""
import os
import time
import unittest
from unittest.mock import MagicMock, patch

# Patch Redis before import so the module doesn't try to connect
_mock_redis = MagicMock()
_mock_redis.get.return_value = None

import atlas.feature_flag as ff_module
ff_module._redis = _mock_redis


from atlas.feature_flag import FeatureFlag, _cache, _cache_bust


class TestFeatureFlagDefaults(unittest.TestCase):

    def setUp(self):
        # Clear cache before each test
        _cache.clear()
        _mock_redis.get.return_value = None

    def test_returns_default_when_no_overrides(self):
        val = FeatureFlag.value("atlas.usage_aggregates_v2")
        self.assertEqual(val, "off")

    def test_on_returns_false_for_off_default(self):
        self.assertFalse(FeatureFlag.on("atlas.usage_aggregates_v2"))

    def test_on_returns_true_for_on_default(self):
        # atlas.copy.empty_state defaults to "on"
        self.assertTrue(FeatureFlag.on("atlas.copy.empty_state"))

    def test_prompt_evolution_off_by_default(self):
        self.assertFalse(FeatureFlag.on("atlas.prompt_evolution"))

    def test_bandit_algo_defaults_to_thompson(self):
        val = FeatureFlag.value("atlas.bandit.algo")
        self.assertEqual(val, "thompson")

    def test_temperature_returns_float(self):
        val = FeatureFlag.value("atlas.bandit.temperature")
        self.assertIsInstance(val, float)
        self.assertAlmostEqual(val, 1.0)

    def test_min_impressions_floor_returns_int(self):
        val = FeatureFlag.value("atlas.bandit.min_impressions_floor")
        self.assertIsInstance(val, int)
        self.assertEqual(val, 50)


class TestFeatureFlagRedisOverride(unittest.TestCase):

    def setUp(self):
        _cache.clear()

    def test_redis_global_override_wins(self):
        _mock_redis.get.return_value = "on"
        self.assertTrue(FeatureFlag.on("atlas.usage_aggregates_v2"))
        _mock_redis.get.return_value = None

    def test_redis_off_override(self):
        _mock_redis.get.return_value = "off"
        self.assertFalse(FeatureFlag.on("atlas.copy.empty_state"))
        _mock_redis.get.return_value = None

    def test_fallback_value(self):
        _mock_redis.get.return_value = "fallback"
        self.assertTrue(FeatureFlag.fallback("atlas.copy.empty_state"))
        self.assertFalse(FeatureFlag.on("atlas.copy.empty_state"))
        _mock_redis.get.return_value = None

    def test_per_tenant_override_wins_over_global(self):
        def tenant_aware_get(key):
            if "tenant:t1" in key:
                return "off"      # tenant override
            return "on"           # global

        _mock_redis.get.side_effect = tenant_aware_get
        # Tenant t1 → off
        self.assertFalse(FeatureFlag.on("atlas.usage_aggregates_v2", tenant_id="t1"))
        # No specific tenant → global on
        _cache.clear()  # bust cache between calls
        self.assertTrue(FeatureFlag.on("atlas.usage_aggregates_v2"))
        _mock_redis.get.side_effect = None
        _mock_redis.get.return_value = None


class TestFeatureFlagCaching(unittest.TestCase):

    def setUp(self):
        _cache.clear()
        _mock_redis.get.return_value = None

    def test_result_cached_on_second_call(self):
        FeatureFlag.value("atlas.usage_aggregates_v2")
        _mock_redis.get.reset_mock()
        FeatureFlag.value("atlas.usage_aggregates_v2")
        # Redis should NOT have been called on the second request
        _mock_redis.get.assert_not_called()

    def test_set_busts_cache(self):
        FeatureFlag.value("atlas.usage_aggregates_v2")  # populate cache
        FeatureFlag.set("atlas.usage_aggregates_v2", "on")
        # Cache should be empty for that key
        key = "featureflag:atlas.usage_aggregates_v2"
        self.assertNotIn(key, _cache)


class TestFeatureFlagAll(unittest.TestCase):

    def setUp(self):
        _cache.clear()
        _mock_redis.get.return_value = None

    def test_all_returns_all_known_flags(self):
        all_flags = FeatureFlag.all()
        expected = [
            "atlas.usage_aggregates_v2",
            "atlas.bandit.algo",
            "atlas.bandit.temperature",
            "atlas.prompt_evolution",
        ]
        for flag in expected:
            self.assertIn(flag, all_flags)

    def test_all_returns_dict(self):
        self.assertIsInstance(FeatureFlag.all(), dict)


class TestParityWithPHP(unittest.TestCase):
    """
    Documents the parity contract between Python and PHP clients.
    Both must resolve the same flags to the same values given the same Redis state.
    """

    def test_default_values_match_php_config(self):
        """PHP config/features.php defaults must match Python _DEFAULTS."""
        php_defaults = {
            "atlas.usage_aggregates_v2":            "off",
            "atlas.usage_aggregates_v2.shadow":     "off",
            "atlas.usage_aggregates_v2.cutover":    "off",
            "atlas.usage_aggregates_v2.rollback":   "off",
            "atlas.copy.empty_state":               "on",
            "atlas.copy.banner":                    "on",
            "atlas.copy.modal":                     "on",
            "atlas.copy.tooltip":                   "on",
            "atlas.copy.success_state":             "on",
            "atlas.copy.error_state":               "on",
            "atlas.prompt_evolution":               "off",
            "atlas.bandit.algo":                    "thompson",
        }
        _cache.clear()
        _mock_redis.get.return_value = None

        for flag, expected in php_defaults.items():
            actual = FeatureFlag.value(flag)
            self.assertEqual(
                str(actual), str(expected),
                f"Flag '{flag}': Python default '{actual}' != PHP default '{expected}'"
            )


if __name__ == "__main__":
    unittest.main()
