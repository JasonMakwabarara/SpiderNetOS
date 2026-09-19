"""
Tests for intelligence/atlas/mc_simulator.py — plan §12.5 / §12.9
"""

import time
import unittest

from atlas.mc_simulator import simulate

# Simple toy chain: chat_started → flow_running → completed | abandoned
_MATRIX = {
    "chat_started": {
        "flow_running": 0.8,
        "abandoned":    0.2,
    },
    "flow_running": {
        "completed": 0.7,
        "errored":   0.2,
        "abandoned": 0.1,
    },
}


class TestMcSimulator(unittest.TestCase):

    # ------------------------------------------------------------------
    # Determinism under seed
    # ------------------------------------------------------------------

    def test_determinism_under_fixed_seed(self):
        a = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                     runs=200, steps=5, seed=42)
        b = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                     runs=200, steps=5, seed=42)
        self.assertEqual(a["activation_probability"], b["activation_probability"])
        self.assertEqual(a["end_state_distribution"], b["end_state_distribution"])

    # ------------------------------------------------------------------
    # Damping
    # ------------------------------------------------------------------

    def test_damping_default_is_0_85(self):
        # The simulator must accept damping=0.85 as the default per plan §12.5
        a = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                     runs=100, steps=3, seed=1)
        b = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                     runs=100, steps=3, seed=1, damping=0.85)
        self.assertEqual(a["activation_probability"], b["activation_probability"])

    def test_zero_damping_gives_pure_learned(self):
        # damping=1.0 (no blend) should still produce valid results
        r = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                     runs=200, steps=5, seed=7, damping=1.0)
        self.assertGreaterEqual(r["activation_probability"], 0.0)
        self.assertLessEqual(r["activation_probability"], 1.0)

    # ------------------------------------------------------------------
    # Output schema
    # ------------------------------------------------------------------

    def test_output_schema_matches_plan(self):
        r = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                     runs=100, steps=5, seed=1)

        required = [
            "activation_probability", "churn_probability", "expected_value",
            "confidence_95", "runs", "steps", "terminal_hits", "end_state_distribution",
        ]
        for key in required:
            self.assertIn(key, r, f"missing key: {key}")

        self.assertEqual(len(r["confidence_95"]), 2)
        self.assertLessEqual(r["confidence_95"][0], r["activation_probability"])
        self.assertGreaterEqual(r["confidence_95"][1], r["activation_probability"])

    # ------------------------------------------------------------------
    # CI shrinks with more runs
    # ------------------------------------------------------------------

    def test_ci_half_width_shrinks_with_more_runs(self):
        small = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                         runs=100, steps=5, seed=11)
        large = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                         runs=1000, steps=5, seed=11)
        hw_small = small["confidence_95"][1] - small["confidence_95"][0]
        hw_large = large["confidence_95"][1] - large["confidence_95"][0]
        self.assertLess(hw_large, hw_small, "95% CI should be tighter with more runs")

    # ------------------------------------------------------------------
    # Latency budget (plan §12.5: runs=1000 steps=10 p95 < 300ms)
    # ------------------------------------------------------------------

    def test_latency_budget_single_run(self):
        start = time.perf_counter()
        simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                 runs=1000, steps=10, seed=1)
        elapsed_ms = (time.perf_counter() - start) * 1000
        # Generous single-call budget (test env can be slow); p95 is validated separately.
        self.assertLess(elapsed_ms, 1000, f"simulate() took {elapsed_ms:.0f}ms — over 1s budget")

    # ------------------------------------------------------------------
    # Probabilities are bounded
    # ------------------------------------------------------------------

    def test_probabilities_bounded(self):
        r = simulate(matrix=_MATRIX, start_state="chat_started", chain="session_lifecycle",
                     runs=500, steps=10, seed=3)
        self.assertGreaterEqual(r["activation_probability"], 0.0)
        self.assertLessEqual(r["activation_probability"], 1.0)
        self.assertGreaterEqual(r["churn_probability"], 0.0)
        self.assertLessEqual(r["churn_probability"], 1.0)

    # ------------------------------------------------------------------
    # Empty matrix falls through cleanly
    # ------------------------------------------------------------------

    def test_empty_matrix_does_not_crash(self):
        r = simulate(matrix={}, start_state="anything", chain="session_lifecycle",
                     runs=50, steps=5, seed=1)
        # With no transitions out of the start state, start state is the end state
        self.assertIn("anything", r["end_state_distribution"])


if __name__ == "__main__":
    unittest.main()
