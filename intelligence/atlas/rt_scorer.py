"""
Atlas RT Scorer (§4.3)

Scores copy variants using transformation-biased weights.
Returns a CopyScore with per-dimension breakdown and a final TS value.

All weights are configurable at runtime via FeatureFlag (future extension).
The hard-coded defaults implement the plan §4.3.1–4.3.3 specification.
"""

from __future__ import annotations

import math
import re
from dataclasses import dataclass, field
from typing import Literal

Surface = Literal[
    "empty_state", "banner", "modal", "tooltip", "success_state", "error_state"
]

# ---------------------------------------------------------------------------
# Weight configurations (§4.3.3)
# ---------------------------------------------------------------------------

_SURFACE_WEIGHTS: dict[str, dict[str, float]] = {
    "empty_state": {
        "value_perception": 0.30,
        "emotional_impact": 0.25,
        "clarity":          0.20,
        "actionability":    0.15,
        "trust":            0.10,
    },
    "banner": {
        "value_perception": 0.35,
        "clarity":          0.30,
        "brevity_bonus":    0.20,
        "emotional_impact": 0.15,
    },
    "modal": {
        "value_perception": 0.30,
        "actionability":    0.25,
        "trust":            0.25,
        "emotional_impact": 0.20,
    },
    "tooltip": {
        "clarity":       0.50,
        "brevity_bonus": 0.30,
        "value_perception": 0.20,
    },
    "success_state": {
        "value_perception": 0.35,
        "emotional_impact": 0.30,
        "clarity":          0.20,
        "actionability":    0.15,
    },
    "error_state": {
        "trust":            0.40,
        "clarity":          0.35,
        "value_perception": 0.25,
    },
}

# Global transformation-bias config applied on top of surface weights (§4.3.1)
_GLOBAL_BIAS = {
    "value_perception": +0.20,   # relative multiplier on the dimension raw score
    "emotional_impact": +0.15,
    "brevity_bonus":    -0.10,   # penalty for excessive brevity scoring
}

# Global minimum thresholds (§4.3.2)
MIN_THRESHOLDS: dict[str, float] = {
    "TS":               0.72,
    "value_perception": 0.75,
    "clarity":          0.70,
    "emotional_impact": 0.60,
    "trust":            0.80,
    "cognitive_load":   0.25,   # must be BELOW this
}

# Hard surface constraints (§4.3.3)
_BANNER_MAX_WORDS = 18
_TOOLTIP_MAX_WORDS = 12


# ---------------------------------------------------------------------------
# Data classes
# ---------------------------------------------------------------------------

@dataclass
class CopyUnit:
    """Structured copy representation (§4.3.4)."""
    future_state:   str           # REQUIRED — describes the outcome
    action:         str | None = None    # REQUIRED on interactive surfaces
    value:          str | None = None    # Quantified benefit
    emotional_hook: str | None = None
    full_text:      str = ""      # Rendered text (future_state + value + hook)
    cta_label:      str | None = None


@dataclass
class CopyScore:
    """Per-dimension scores + final TS for a copy variant."""
    ts:               float = 0.0
    value_perception: float = 0.0
    clarity:          float = 0.0
    emotional_impact: float = 0.0
    trust:            float = 0.0
    actionability:    float = 0.0
    brevity_bonus:    float = 0.0
    cognitive_load:   float = 0.0
    passes_gates:     bool  = False
    gate_failures:    list[str] = field(default_factory=list)


# ---------------------------------------------------------------------------
# Scorer
# ---------------------------------------------------------------------------

class RTScorer:
    """
    Real-time copy scorer.

    score(copy, surface) → CopyScore
    """

    def score(self, copy: CopyUnit, surface: str) -> CopyScore:
        text = copy.full_text or copy.future_state or ""
        cs   = CopyScore()

        # --- Raw dimension scores ---
        cs.value_perception = self._value_perception(copy, surface)
        cs.clarity          = self._clarity(text)
        cs.emotional_impact = self._emotional_impact(copy, text)
        cs.trust            = self._trust(text)
        cs.actionability    = self._actionability(copy, surface)
        cs.brevity_bonus    = self._brevity_bonus(text, surface)
        cs.cognitive_load   = self._cognitive_load(text)

        # --- Apply transformation bias (§4.3.1) ---
        cs.value_perception = min(1.0, cs.value_perception * (1 + _GLOBAL_BIAS["value_perception"]))
        cs.emotional_impact = min(1.0, cs.emotional_impact * (1 + _GLOBAL_BIAS["emotional_impact"]))
        # brevity_bonus is penalised slightly
        cs.brevity_bonus    = max(0.0, cs.brevity_bonus * (1 + _GLOBAL_BIAS["brevity_bonus"]))

        # --- Weighted TS ---
        weights  = _SURFACE_WEIGHTS.get(surface, _SURFACE_WEIGHTS["empty_state"])
        dim_map  = {
            "value_perception": cs.value_perception,
            "clarity":          cs.clarity,
            "emotional_impact": cs.emotional_impact,
            "trust":            cs.trust,
            "actionability":    cs.actionability,
            "brevity_bonus":    cs.brevity_bonus,
        }
        total_weight = sum(weights.values())
        ts = sum(dim_map.get(d, 0.0) * w for d, w in weights.items()) / max(total_weight, 1e-9)
        cs.ts = round(min(1.0, ts), 4)

        # --- Hard surface rules ---
        failures = self._check_gates(cs, copy, surface)
        cs.gate_failures = failures
        cs.passes_gates  = len(failures) == 0

        return cs

    # -----------------------------------------------------------------------
    # Dimension scorers
    # -----------------------------------------------------------------------

    def _value_perception(self, copy: CopyUnit, surface: str) -> float:
        """
        Does the text communicate a meaningful benefit or outcome?
        Higher when: future_state is present, value is quantified, action is outcome-led.
        """
        score = 0.0
        text  = (copy.full_text or copy.future_state or "").lower()
        if copy.future_state:
            score += 0.5
        if copy.value:
            score += 0.3
            # Bonus for quantified values
            if any(c.isdigit() for c in copy.value) or any(
                kw in copy.value.lower() for kw in ["hour", "minute", "day", "week", "%", "save", "faster"]
            ):
                score += 0.1
        # Transformation language bonus
        transform_words = ["automat", "streamlin", "insight", "visib", "accurate", "live", "real-time"]
        if any(tw in text for tw in transform_words):
            score += 0.1
        return min(1.0, score)

    def _clarity(self, text: str) -> float:
        """Penalise jargon, passive voice, and excessive length."""
        if not text:
            return 0.0
        word_count = len(text.split())
        # Base clarity by length (sweet spot 10–30 words)
        if word_count <= 5:
            base = 0.5
        elif word_count <= 30:
            base = 1.0 - (word_count - 10) * 0.01 if word_count > 10 else 1.0
        else:
            base = max(0.3, 1.0 - (word_count - 30) * 0.02)

        # Jargon penalty
        jargon = ["utilise", "leverage", "synergy", "paradigm", "holistic", "sql", "json",
                  "endpoint", "migration", "schema", "database", "query"]
        jargon_hits = sum(1 for j in jargon if j in text.lower())
        base -= jargon_hits * 0.1

        return max(0.0, min(1.0, base))

    def _emotional_impact(self, copy: CopyUnit, text: str) -> float:
        """Measures motivational or trust-building language."""
        score = 0.2  # baseline
        if copy.emotional_hook:
            score += 0.4
        positive_words = ["live", "accurate", "instantly", "automatically", "confident",
                          "effortless", "always", "reliable", "clear", "complete", "full"]
        hits = sum(1 for pw in positive_words if pw in text.lower())
        score += min(0.4, hits * 0.08)
        return min(1.0, score)

    def _trust(self, text: str) -> float:
        """Does the copy avoid hype and sound credible?"""
        hype = ["revolutionize", "game-changer", "massive gains", "guaranteed",
                "unlimited", "100%", "never fail", "perfect"]
        hype_hits = sum(1 for h in hype if h in text.lower())
        score = max(0.0, 1.0 - hype_hits * 0.25)
        # Forward-looking without over-promising is fine
        if "we're working on it" in text.lower() or "your data is safe" in text.lower():
            score = min(1.0, score + 0.1)
        return score

    def _actionability(self, copy: CopyUnit, surface: str) -> float:
        """Is there a clear next step?"""
        if surface == "tooltip":
            return 1.0  # tooltips don't need CTAs
        if copy.action or copy.cta_label:
            label = (copy.cta_label or copy.action or "").lower()
            # Generic CTA penalty
            if label in ["click here", "learn more", "ok", "submit"]:
                return 0.4
            return 0.9
        return 0.1  # No CTA on interactive surface

    def _brevity_bonus(self, text: str, surface: str) -> float:
        """Bonus for being concise on high-density surfaces."""
        words = len(text.split())
        if surface == "banner":
            return 1.0 if words <= _BANNER_MAX_WORDS else max(0.0, 1.0 - (words - _BANNER_MAX_WORDS) * 0.1)
        if surface == "tooltip":
            return 1.0 if words <= _TOOLTIP_MAX_WORDS else max(0.0, 1.0 - (words - _TOOLTIP_MAX_WORDS) * 0.15)
        return 0.5  # Neutral for other surfaces (brevity is not a primary weight)

    def _cognitive_load(self, text: str) -> float:
        """Estimate cognitive load (lower is better). Penalise nested clauses, jargon."""
        words    = len(text.split())
        sentences = max(1, len(re.split(r'[.!?]', text)))
        avg_len   = words / sentences
        # High avg sentence length → higher load
        load = min(1.0, max(0.0, (avg_len - 8) / 20))
        return round(load, 4)

    # -----------------------------------------------------------------------
    # Gate checks
    # -----------------------------------------------------------------------

    def _check_gates(self, cs: CopyScore, copy: CopyUnit, surface: str) -> list[str]:
        failures = []

        # TS global threshold
        if cs.ts < MIN_THRESHOLDS["TS"]:
            failures.append(f"ts_below_floor ({cs.ts:.3f} < {MIN_THRESHOLDS['TS']})")

        # Trust floor (hard gate — breach forces TS to 0 in §8.3)
        if cs.trust < MIN_THRESHOLDS["trust"]:
            failures.append(f"trust_below_floor ({cs.trust:.3f} < {MIN_THRESHOLDS['trust']})")

        # Cognitive load ceiling
        if cs.cognitive_load > MIN_THRESHOLDS["cognitive_load"]:
            failures.append(f"cognitive_load_too_high ({cs.cognitive_load:.3f})")

        # Surface-specific hard rules
        if surface == "empty_state":
            if not copy.future_state:
                failures.append("empty_state_missing_future_state")
            if not (copy.action or copy.cta_label):
                failures.append("empty_state_missing_cta")

        if surface == "banner":
            word_count = len((copy.full_text or "").split())
            if word_count > _BANNER_MAX_WORDS:
                failures.append(f"banner_too_long ({word_count} words > {_BANNER_MAX_WORDS})")

        if surface == "tooltip":
            word_count = len((copy.full_text or "").split())
            if word_count > _TOOLTIP_MAX_WORDS:
                failures.append(f"tooltip_too_long ({word_count} words > {_TOOLTIP_MAX_WORDS})")

        # Anti-generic detection
        generic = ["no data available", "get started now", "click here", "create a workflow",
                   "configure settings"]
        text_lower = (copy.full_text or "").lower()
        for g in generic:
            if g in text_lower:
                failures.append(f"generic_copy_detected: '{g}'")

        return failures
