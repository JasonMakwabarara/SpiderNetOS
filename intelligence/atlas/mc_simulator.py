"""
Monte Carlo simulator for the State Transition Engine (plan §12.5).

Takes a damped transition matrix and simulates N walks of H steps from a
given start state. Returns probability estimates with 95% CIs.

Pure-Python (stdlib random only) to keep inference-plane deps minimal.
Deterministic under ``seed`` so tests can assert convergence.

Latency budget: runs=1000, steps=10 must complete in < 300ms single core.
"""

from __future__ import annotations

import math
import random
from dataclasses import asdict, dataclass
from typing import Mapping


@dataclass
class SimulationResult:
    activation_probability: float
    churn_probability: float
    expected_value: float
    confidence_95: list[float]
    runs: int
    steps: int
    terminal_hits: dict[str, int]
    end_state_distribution: dict[str, float]


# Known "activation" targets per chain; mirrors StateTransitionEngine::targetStateFor.
_ACTIVATION_TARGETS = {
    "session_lifecycle": {"completed"},
    "tenant_lifecycle": {"active", "expanded"},
}
_CHURN_TARGETS = {
    "session_lifecycle": {"abandoned", "errored"},
    "tenant_lifecycle": {"churned"},
}


def simulate(
    matrix: Mapping[str, Mapping[str, float]],
    start_state: str,
    chain: str = "session_lifecycle",
    steps: int = 10,
    runs: int = 1000,
    terminal_states: list[str] | None = None,
    damping: float = 0.85,
    seed: int | None = None,
) -> dict:
    """Run ``runs`` walks of at most ``steps`` transitions each.

    Returns a dict suitable for JSON serialisation (``SimulationResult`` fields).
    """
    steps = max(1, min(50, int(steps)))
    runs  = max(10, min(5000, int(runs)))

    rng = random.Random(seed) if seed is not None else random

    terminal_set = set(terminal_states or _CHURN_TARGETS.get(chain, set()))
    activation_set = _ACTIVATION_TARGETS.get(chain, set())
    churn_set = _CHURN_TARGETS.get(chain, set())

    activation_hits = 0
    churn_hits      = 0
    end_states: dict[str, int] = {}
    terminal_hits: dict[str, int] = {t: 0 for t in terminal_set}

    for _ in range(runs):
        state = start_state
        for _ in range(steps):
            row = matrix.get(state)
            if not row:
                break
            state = _sample_next(row, rng, damping)
            if state in terminal_set:
                terminal_hits[state] = terminal_hits.get(state, 0) + 1
                break

        end_states[state] = end_states.get(state, 0) + 1
        if state in activation_set:
            activation_hits += 1
        if state in churn_set:
            churn_hits += 1

    activation_p = activation_hits / runs
    churn_p      = churn_hits / runs

    # 95% CI on activation_p via normal approximation: 1.96 * sqrt(p(1-p)/n)
    half_width = 1.96 * math.sqrt(max(1e-12, activation_p * (1 - activation_p) / runs))
    ci = [
        round(max(0.0, activation_p - half_width), 6),
        round(min(1.0, activation_p + half_width), 6),
    ]

    total = max(1, sum(end_states.values()))
    end_dist = {s: round(c / total, 6) for s, c in end_states.items()}

    result = SimulationResult(
        activation_probability=round(activation_p, 6),
        churn_probability=round(churn_p, 6),
        expected_value=round(activation_p - churn_p, 6),
        confidence_95=ci,
        runs=runs,
        steps=steps,
        terminal_hits=terminal_hits,
        end_state_distribution=end_dist,
    )
    return asdict(result)


# ---------------------------------------------------------------------------
# Private
# ---------------------------------------------------------------------------

def _sample_next(row: Mapping[str, float], rng: random.Random, damping: float) -> str:
    """Sample next state from a (possibly unnormalised) row with damping.

    The row probabilities are assumed to be the damped matrix already produced by
    ``StateTransitionEngine::matrix`` (so the controller passes them straight through).
    We additionally clamp to ensure numerical safety at the tails.
    """
    states = list(row.keys())
    weights = [max(0.0, float(row[s])) for s in states]
    total = sum(weights)
    if total <= 0:
        # Uniform fallback
        return rng.choice(states)

    # Normalise & damp (blend with uniform over the row's support)
    uniform_p = 1.0 / len(states)
    probs = [damping * (w / total) + (1 - damping) * uniform_p for w in weights]
    probs_sum = sum(probs)
    probs = [p / probs_sum for p in probs]

    # Inverse-CDF sample
    r = rng.random()
    cumulative = 0.0
    for s, p in zip(states, probs):
        cumulative += p
        if r <= cumulative:
            return s
    return states[-1]
