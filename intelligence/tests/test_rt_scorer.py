"""
Tests for intelligence/atlas/rt_scorer.py

Verifies:
  - All surfaces score against MIN_THRESHOLDS
  - Trust floor blocks hype copy
  - Transformation bias applied correctly
  - Surface-specific hard rules enforced (banner length, empty_state CTA required)
  - Approved fallback templates pass gates
"""
import unittest

from atlas.rt_scorer import MIN_THRESHOLDS, CopyUnit, RTScorer


class TestRTScorerMinimums(unittest.TestCase):

    def setUp(self):
        self.scorer = RTScorer()

    def _good_copy(self, surface: str) -> CopyUnit:
        return CopyUnit(
            future_state="Your usage insights are live and accurate",
            value="see every request, token, and dollar tracked automatically",
            action="View dashboard",
            cta_label="Open dashboard",
            full_text="Your usage insights are live and accurate — see every request tracked.",
        )

    def test_good_copy_passes_gates_on_all_surfaces(self):
        surfaces = ["empty_state", "banner", "modal", "tooltip", "success_state", "error_state"]
        for surface in surfaces:
            copy = self._good_copy(surface)
            # Shorten for tooltip / banner surfaces
            if surface == "tooltip":
                copy.full_text = "See exactly where your budget goes — clarity in every click."
                copy.cta_label = None
                copy.action = None
            elif surface == "banner":
                copy.full_text = "Your usage insights are live — see your spend today."
            score = self.scorer.score(copy, surface)
            self.assertGreaterEqual(score.ts, MIN_THRESHOLDS["TS"],
                msg=f"{surface}: TS {score.ts} below floor {MIN_THRESHOLDS['TS']}")

    def test_hype_copy_fails_trust_gate(self):
        copy = CopyUnit(
            future_state="Revolutionize your business",
            full_text="Revolutionize your business with massive gains guaranteed.",
        )
        score = self.scorer.score(copy, "empty_state")
        self.assertFalse(score.passes_gates)
        self.assertLess(score.trust, MIN_THRESHOLDS["trust"])
        self.assertTrue(any("trust" in f for f in score.gate_failures))

    def test_empty_state_missing_cta_fails(self):
        copy = CopyUnit(
            future_state="See your usage clearly",
            full_text="See your usage clearly every day.",
            # No action or cta_label
        )
        score = self.scorer.score(copy, "empty_state")
        self.assertFalse(score.passes_gates)
        self.assertIn("empty_state_missing_cta", score.gate_failures)

    def test_banner_too_long_fails(self):
        copy = CopyUnit(
            future_state="Track costs",
            full_text=" ".join(["word"] * 25),  # 25 words > 18 limit
            cta_label="Open",
            action="navigate:/usage",
        )
        score = self.scorer.score(copy, "banner")
        self.assertFalse(score.passes_gates)
        self.assertTrue(any("banner_too_long" in f for f in score.gate_failures))

    def test_generic_copy_rejected(self):
        copy = CopyUnit(
            future_state="",
            full_text="No data available. Get started now.",
        )
        score = self.scorer.score(copy, "empty_state")
        generic_failures = [f for f in score.gate_failures if "generic_copy_detected" in f]
        self.assertTrue(len(generic_failures) > 0)


class TestRTScorerTransformationBias(unittest.TestCase):

    def setUp(self):
        self.scorer = RTScorer()

    def test_value_perception_boosted_by_transformation_bias(self):
        copy_with_value = CopyUnit(
            future_state="Keep costs under control automatically",
            value="save 3 hours per week",
            full_text="Keep costs under control automatically — save 3 hours per week.",
        )
        copy_without_value = CopyUnit(
            future_state="Monitor costs",
            full_text="Monitor costs now.",
        )
        score_with = self.scorer.score(copy_with_value, "empty_state")
        score_without = self.scorer.score(copy_without_value, "empty_state")
        self.assertGreater(score_with.value_perception, score_without.value_perception)

    def test_transformation_language_boosts_value_perception(self):
        copy = CopyUnit(
            future_state="Automate your usage tracking",
            full_text="Automate your usage tracking with real-time insights.",
        )
        score = self.scorer.score(copy, "banner")
        self.assertGreater(score.value_perception, 0.5)


class TestFallbackTemplatesPassGates(unittest.TestCase):
    """Approved fallback templates must all pass the TS gate."""

    FALLBACKS = {
        "empty_state": CopyUnit(
            future_state="Your usage insights will appear here once your first request is processed",
            value="see exactly where your spend goes, every day",
            action="navigate:/docs",
            cta_label="View documentation",
            full_text="Your usage insights will appear here once your first request is processed — see exactly where your spend goes, every day.",
        ),
        "banner": CopyUnit(
            future_state="Your usage insights are live",
            value="see where your spend is going today",
            action="navigate:/usage",
            cta_label="Open dashboard",
            full_text="Your usage insights are live — see where your spend is going today.",
        ),
        "tooltip": CopyUnit(
            future_state="Total API requests processed on this day",
            full_text="Total API requests processed on this day.",
        ),
    }

    def test_empty_state_fallback_passes(self):
        scorer = RTScorer()
        score = scorer.score(self.FALLBACKS["empty_state"], "empty_state")
        self.assertGreaterEqual(score.ts, MIN_THRESHOLDS["TS"],
            f"Fallback empty_state TS {score.ts} < {MIN_THRESHOLDS['TS']}: {score.gate_failures}")

    def test_banner_fallback_passes(self):
        scorer = RTScorer()
        score = scorer.score(self.FALLBACKS["banner"], "banner")
        self.assertGreaterEqual(score.ts, MIN_THRESHOLDS["TS"],
            f"Fallback banner TS {score.ts} < {MIN_THRESHOLDS['TS']}: {score.gate_failures}")

    def test_tooltip_fallback_passes(self):
        scorer = RTScorer()
        score = scorer.score(self.FALLBACKS["tooltip"], "tooltip")
        self.assertGreaterEqual(score.ts, MIN_THRESHOLDS["TS"],
            f"Fallback tooltip TS {score.ts} < {MIN_THRESHOLDS['TS']}: {score.gate_failures}")


if __name__ == "__main__":
    unittest.main()
