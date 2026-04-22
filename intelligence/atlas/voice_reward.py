"""
SpiderNet OS — Voice Reward Function (Phase E)

Computes a composite per-call reward signal for the Atlas bandit / Thompson
sampling infrastructure.  Consumed by the post-call DAG:

  ProcessVoiceCallSummary → POST /atlas/copy/{variantId}/event {reward: float}

Reward components (all normalised to [0.0, 1.0]):

  1. completion_score   – Did the call complete without a transfer?  (0 or 1)
  2. sentiment_score    – Positive=1, Neutral=0.5, Negative=0
  3. turn_efficiency    – 1 / (1 + normalised_turn_count)  (shorter = better)
  4. latency_score      – 1 if p95 latency met, linear decay above threshold

Composite: weighted sum (weights configurable via env).

Usage:
    from atlas.voice_reward import compute_reward
    reward = compute_reward(call_data, summary_data)
    # reward: float in [0, 1]
"""

from __future__ import annotations

import os
from typing import Any, Dict, Optional

# ─── Weights (configurable) ─────────────────────────────────────────────────

W_COMPLETION = float(os.getenv("VOICE_REWARD_W_COMPLETION", "0.35"))
W_SENTIMENT  = float(os.getenv("VOICE_REWARD_W_SENTIMENT",  "0.30"))
W_EFFICIENCY = float(os.getenv("VOICE_REWARD_W_EFFICIENCY", "0.20"))
W_LATENCY    = float(os.getenv("VOICE_REWARD_W_LATENCY",    "0.15"))

# Latency threshold (ms) above which latency score decays
LATENCY_TARGET_MS = int(os.getenv("VOICE_REWARD_LATENCY_TARGET_MS", "2000"))

# Baseline turn count considered "efficient" (≤ this = full efficiency score)
EFFICIENT_TURN_COUNT = int(os.getenv("VOICE_REWARD_EFFICIENT_TURNS", "4"))


# ─── Public API ──────────────────────────────────────────────────────────────

def compute_reward(
    call: Dict[str, Any],
    summary: Optional[Dict[str, Any]] = None,
) -> float:
    """
    Compute a composite reward scalar for a completed voice call.

    Args:
        call:    dict with keys:
                   status str, duration_seconds int, actions_taken list,
                   transcript list, metadata dict (may contain avg_turn_latency_ms)
        summary: dict with keys: sentiment str, follow_up_tasks list

    Returns:
        float in [0.0, 1.0]
    """
    completion = _completion_score(call)
    sentiment  = _sentiment_score(summary)
    efficiency = _efficiency_score(call)
    latency    = _latency_score(call)

    reward = (
        W_COMPLETION * completion
        + W_SENTIMENT  * sentiment
        + W_EFFICIENCY * efficiency
        + W_LATENCY    * latency
    )

    return round(min(max(reward, 0.0), 1.0), 4)


# ─── Component scoring ───────────────────────────────────────────────────────

def _completion_score(call: Dict[str, Any]) -> float:
    """
    1.0 if call ended without a transfer (caller's need was fully addressed).
    0.0 if transfer was performed.
    0.5 if call failed / unknown.
    """
    status      = call.get("status", "")
    actions     = call.get("actions_taken") or []

    if status == "completed" and "transfer_call" not in _flatten_actions(actions):
        return 1.0
    if status in ("failed", "busy", "no-answer"):
        return 0.5
    return 0.0   # transferred


def _sentiment_score(summary: Optional[Dict[str, Any]]) -> float:
    """Map sentiment label to scalar."""
    if not summary:
        return 0.5   # neutral default when no summary

    sentiment = (summary.get("sentiment") or "neutral").lower()
    return {
        "positive": 1.0,
        "neutral":  0.5,
        "negative": 0.0,
    }.get(sentiment, 0.5)


def _efficiency_score(call: Dict[str, Any]) -> float:
    """
    Based on number of transcript turns.
    ≤ EFFICIENT_TURN_COUNT → 1.0; each turn above reduces score.
    """
    transcript = call.get("transcript") or []
    turns = len([t for t in transcript if t.get("speaker") == "agent"])

    if turns <= EFFICIENT_TURN_COUNT:
        return 1.0

    # Decay: 1 / (1 + excess_turns)
    excess = turns - EFFICIENT_TURN_COUNT
    return round(1.0 / (1.0 + excess), 4)


def _latency_score(call: Dict[str, Any]) -> float:
    """
    1.0 if avg turn latency ≤ LATENCY_TARGET_MS.
    Linear decay toward 0 as latency approaches 2× target.
    """
    meta     = call.get("metadata") or {}
    avg_ms   = meta.get("avg_turn_latency_ms")

    if avg_ms is None:
        return 0.75   # no data — give partial credit

    if avg_ms <= LATENCY_TARGET_MS:
        return 1.0

    # Linear decay to 0 at 2× target
    excess_ratio = (avg_ms - LATENCY_TARGET_MS) / LATENCY_TARGET_MS
    return round(max(0.0, 1.0 - excess_ratio), 4)


def _flatten_actions(actions: Any) -> list:
    """Safely flatten actions_taken which may be a list of dicts or strings."""
    if not actions:
        return []
    flat = []
    for a in actions:
        if isinstance(a, str):
            flat.append(a)
        elif isinstance(a, dict):
            flat.append(a.get("tool") or a.get("name") or "")
    return flat
