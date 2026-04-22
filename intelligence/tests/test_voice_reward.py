"""
VoiceReward tests — Phase E

Tests for the composite reward function used by the Atlas bandit.
"""

import pytest
from atlas.voice_reward import (
    compute_reward,
    _completion_score,
    _sentiment_score,
    _efficiency_score,
    _latency_score,
)


# ─── Fixtures ────────────────────────────────────────────────────────────────

def completed_call(**overrides):
    base = {
        "status": "completed",
        "duration_seconds": 120,
        "actions_taken": [],
        "transcript": [
            {"speaker": "caller", "text": "Hi"},
            {"speaker": "agent",  "text": "Hello"},
            {"speaker": "caller", "text": "I need help"},
            {"speaker": "agent",  "text": "Sure"},
        ],
        "metadata": {"avg_turn_latency_ms": 800},
    }
    base.update(overrides)
    return base


def summary(**overrides):
    base = {"sentiment": "positive", "follow_up_tasks": []}
    base.update(overrides)
    return base


# ─── Completion score ─────────────────────────────────────────────────────────

class TestCompletionScore:
    def test_completed_without_transfer_is_1(self):
        call = completed_call()
        assert _completion_score(call) == 1.0

    def test_completed_with_transfer_is_0(self):
        call = completed_call(actions_taken=["transfer_call"])
        assert _completion_score(call) == 0.0

    def test_failed_call_is_0_5(self):
        call = completed_call(status="failed")
        assert _completion_score(call) == 0.5

    def test_busy_call_is_0_5(self):
        call = completed_call(status="busy")
        assert _completion_score(call) == 0.5


# ─── Sentiment score ──────────────────────────────────────────────────────────

class TestSentimentScore:
    def test_positive_is_1(self):
        assert _sentiment_score({"sentiment": "positive"}) == 1.0

    def test_neutral_is_0_5(self):
        assert _sentiment_score({"sentiment": "neutral"}) == 0.5

    def test_negative_is_0(self):
        assert _sentiment_score({"sentiment": "negative"}) == 0.0

    def test_none_summary_defaults_neutral(self):
        assert _sentiment_score(None) == 0.5

    def test_unknown_sentiment_defaults_neutral(self):
        assert _sentiment_score({"sentiment": "confused"}) == 0.5


# ─── Efficiency score ─────────────────────────────────────────────────────────

class TestEfficiencyScore:
    def test_few_turns_is_1(self):
        call = completed_call(transcript=[
            {"speaker": "caller", "text": "Hi"},
            {"speaker": "agent",  "text": "Hello"},
        ])
        assert _efficiency_score(call) == 1.0

    def test_many_turns_decays(self):
        # 10 agent turns — should be < 1
        transcript = [{"speaker": "agent" if i % 2 else "caller", "text": f"t{i}"} for i in range(20)]
        call = completed_call(transcript=transcript)
        score = _efficiency_score(call)
        assert 0.0 < score < 1.0

    def test_empty_transcript_is_1(self):
        call = completed_call(transcript=[])
        assert _efficiency_score(call) == 1.0


# ─── Latency score ────────────────────────────────────────────────────────────

class TestLatencyScore:
    def test_under_target_is_1(self):
        call = completed_call(metadata={"avg_turn_latency_ms": 500})
        assert _latency_score(call) == 1.0

    def test_at_target_is_1(self):
        call = completed_call(metadata={"avg_turn_latency_ms": 2000})
        assert _latency_score(call) == 1.0

    def test_double_target_is_0(self):
        call = completed_call(metadata={"avg_turn_latency_ms": 4000})
        assert _latency_score(call) == 0.0

    def test_missing_latency_gives_partial(self):
        call = completed_call(metadata={})
        score = _latency_score(call)
        assert 0.5 <= score <= 1.0


# ─── Composite reward ─────────────────────────────────────────────────────────

class TestComputeReward:
    def test_perfect_call_near_1(self):
        call = completed_call(metadata={"avg_turn_latency_ms": 600})
        s    = summary(sentiment="positive")
        reward = compute_reward(call, s)
        assert reward >= 0.85

    def test_failed_negative_call_near_0(self):
        call   = completed_call(status="failed", metadata={"avg_turn_latency_ms": 5000})
        s      = summary(sentiment="negative")
        reward = compute_reward(call, s)
        assert reward <= 0.40

    def test_reward_bounded_0_1(self):
        call   = completed_call()
        s      = summary()
        reward = compute_reward(call, s)
        assert 0.0 <= reward <= 1.0

    def test_no_summary_still_returns_value(self):
        call   = completed_call()
        reward = compute_reward(call, None)
        assert 0.0 <= reward <= 1.0

    def test_transfer_lowers_reward(self):
        good = completed_call(actions_taken=[])
        bad  = completed_call(actions_taken=["transfer_call"])
        s    = summary(sentiment="positive")
        assert compute_reward(good, s) > compute_reward(bad, s)
