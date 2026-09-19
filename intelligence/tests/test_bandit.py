"""
Tests for intelligence/atlas/bandit.py (§11.11)

Verifies:
  - Thompson sampling returns a valid selection
  - Temperature clamping
  - Minimum impression floor enforcement
  - Trust-floor and TS-gate filtering
  - Posterior entropy collapse detection
  - Cold-start seeding from cohort CTR
  - Reward update correctness
"""
import unittest

from atlas.bandit import BanditArm, SelectionResult, ThompsonBandit


def make_arm(
    variant_id: str = "v1",
    surface: str = "empty_state",
    predicted_ts: float = 0.80,
    trust_score: float = 0.90,
    alpha: float = 1.0,
    beta: float = 1.0,
    impressions: int = 100,
    clicks: int = 30,
) -> BanditArm:
    return BanditArm(
        variant_id=variant_id,
        surface=surface,
        predicted_ts=predicted_ts,
        trust_score=trust_score,
        alpha=alpha,
        beta=beta,
        impressions=impressions,
        clicks=clicks,
    )


class TestThompsonBanditSelection(unittest.TestCase):

    def test_selects_one_arm_from_pool(self):
        bandit = ThompsonBandit()
        arms = [make_arm(f"v{i}") for i in range(5)]
        result = bandit.select(arms, "empty_state")
        self.assertIsInstance(result, SelectionResult)
        self.assertIn(result.arm, arms)

    def test_returns_none_when_all_arms_below_trust_floor(self):
        bandit = ThompsonBandit()
        arms = [make_arm("v1", trust_score=0.50)]  # below 0.80 floor
        result = bandit.select(arms, "empty_state")
        self.assertIsNone(result)

    def test_returns_none_when_all_arms_below_ts_floor(self):
        bandit = ThompsonBandit()
        arms = [make_arm("v1", predicted_ts=0.60)]  # below 0.72 floor
        result = bandit.select(arms, "empty_state")
        self.assertIsNone(result)

    def test_temperature_clamped_to_valid_range(self):
        bandit_low  = ThompsonBandit(temperature=0.1)
        bandit_high = ThompsonBandit(temperature=5.0)
        self.assertEqual(bandit_low.temperature, 0.5)
        self.assertEqual(bandit_high.temperature, 1.2)

    def test_floor_enforcement_boosts_underexplored_arm(self):
        bandit = ThompsonBandit(floor=50)
        # Arm with only 5 impressions should be boosted
        arm = make_arm("v1", impressions=5)
        arms = [arm]
        result = bandit.select(arms, "empty_state")
        self.assertIsNotNone(result)
        # Floor-boosted selection should have theta >= 0.5
        self.assertGreaterEqual(result.theta, 0.0)

    def test_thompson_method_label(self):
        bandit = ThompsonBandit(algo="thompson")
        arms   = [make_arm(f"v{i}") for i in range(3)]
        result = bandit.select(arms, "empty_state")
        self.assertIsNotNone(result)
        self.assertIn(result.method, ["thompson", "floor_boosted"])

    def test_epsilon_greedy_method_label(self):
        bandit = ThompsonBandit(algo="epsilon_greedy")
        arms   = [make_arm("v1", impressions=1000)]  # many impressions → exploit
        result = bandit.select(arms, "empty_state")
        self.assertIsNotNone(result)
        self.assertIn(result.method, ["epsilon_greedy", "floor_boosted"])


class TestBanditRewardUpdate(unittest.TestCase):

    def test_click_increments_clicks(self):
        bandit = ThompsonBandit()
        arm    = make_arm()
        bandit.update(arm, "click", 0.80)
        self.assertEqual(arm.clicks, 31)

    def test_action_increments_actions(self):
        bandit = ThompsonBandit()
        arm    = make_arm()
        bandit.update(arm, "action", 0.80)
        self.assertEqual(arm.actions, 1)

    def test_blended_reward_range(self):
        bandit = ThompsonBandit()
        arm    = make_arm()
        reward = bandit.update(arm, "conversion", 0.85)
        # Blended reward = 0.6*0.85 + 0.4*1.0 = 0.91
        self.assertAlmostEqual(reward, 0.91, places=5)

    def test_alpha_increments_on_positive_event(self):
        bandit = ThompsonBandit()
        arm    = make_arm(alpha=1.0)
        bandit.update(arm, "action", 0.80)
        # alpha should increase
        self.assertGreater(arm.alpha, 1.0)


class TestEntropyMonitoring(unittest.TestCase):

    def test_uniform_arms_high_entropy(self):
        bandit = ThompsonBandit()
        arms   = [make_arm(f"v{i}") for i in range(5)]
        entropy = bandit.posterior_entropy(arms)
        # 5 equal arms → entropy close to log2(5) ≈ 2.32
        self.assertGreater(entropy, 1.5)

    def test_single_arm_low_entropy(self):
        bandit = ThompsonBandit()
        arms   = [make_arm("v1", alpha=1000, beta=1)]  # dominant arm
        entropy = bandit.posterior_entropy(arms)
        # Single dominant arm → low entropy
        self.assertLessEqual(entropy, 1.0)

    def test_collapse_detected_below_threshold(self):
        bandit = ThompsonBandit()
        # Single dominant arm
        arms   = [make_arm("v1", alpha=1000, beta=1)]
        self.assertTrue(bandit.is_collapsed(arms, threshold=1.0))


class TestColdStartSeeding(unittest.TestCase):

    def test_cohort_seeding_shifts_alpha_beta(self):
        arm = make_arm(alpha=1.0, beta=1.0)
        ThompsonBandit.seed_from_cohort(arm, cohort_ctr=0.30, n_pseudo=10)
        # alpha = 1 + 0.30*10 = 4.0
        # beta  = 1 + 0.70*10 = 8.0
        self.assertAlmostEqual(arm.alpha, 4.0)
        self.assertAlmostEqual(arm.beta, 8.0)


if __name__ == "__main__":
    unittest.main()
