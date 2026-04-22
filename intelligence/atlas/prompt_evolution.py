"""
Atlas Prompt Evolution Macro Cycle (§4.3.11, T12.5)

STATUS: Shipped OFF — enable after D+7 by setting:
    feature:atlas.prompt_evolution = on

This module implements the weekly macro cycle that:
  1. Ranks prompts by composite score
  2. Retires bottom 30 %
  3. Mutates elite 20 % (parameter / instruction / tone / structural)
  4. Generates crossover children from top pairs
  5. Writes new prompts back to atlas_prompts
  6. Enforces anti-degradation guardrails throughout

Run via the scheduled command (wired in backend/routes/console.php):
    php artisan schedule:run   (runs weekly on Sunday 02:00 UTC)

Or trigger manually:
    python -m intelligence.atlas.prompt_evolution --dry-run
"""

from __future__ import annotations

import argparse
import json
import math
import random
import time
import uuid
from dataclasses import dataclass
from typing import Any

try:
    import psycopg2
    import psycopg2.extras
    HAS_PSYCOPG2 = True
except ImportError:
    HAS_PSYCOPG2 = False

from config import DATABASE_URL
from atlas.feature_flag import FeatureFlag
from atlas.rt_scorer import RTScorer, CopyUnit

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

ELITE_FRACTION   = 0.20
RETIRE_FRACTION  = 0.30
EXPLORATION_FRAC = 0.20      # 20 % of new prompts are experimental

MUTATION_TONES   = ["confident", "reassuring", "urgent", "empathetic", "assertive"]
MUTATION_STYLES  = ["outcome_first", "problem_first", "value_first", "question_hook"]
MUTATION_EMPHASIS = ["quantified", "qualitative", "social_proof", "time_saving", "risk_reduction"]

SAFETY_GUARDRAILS = [
    "must_include_outcome_first",
    "no_technical_leakage",
    "no_hype_exaggeration",
]

# ---------------------------------------------------------------------------
# Data classes
# ---------------------------------------------------------------------------

@dataclass
class PromptRecord:
    id:               str
    surface:          str
    components:       dict
    template:         str
    generation:       int
    avg_ts:           float
    avg_ctr:          float
    avg_conversion:   float
    stability:        float
    impressions:      int

    def composite_score(self) -> float:
        """§4.3.11 composite: 0.5*avg_ts + 0.3*conversion + 0.2*stability"""
        return 0.5 * self.avg_ts + 0.3 * self.avg_conversion + 0.2 * self.stability


# ---------------------------------------------------------------------------
# Evolution engine
# ---------------------------------------------------------------------------

class PromptEvolutionEngine:

    def __init__(self, dry_run: bool = False) -> None:
        self.dry_run = dry_run
        self.scorer  = RTScorer()

    def run(self) -> dict[str, Any]:
        if not FeatureFlag.on("atlas.prompt_evolution"):
            return {"status": "skipped", "reason": "feature_flag_off"}

        stats: dict[str, Any] = {
            "run_at":    time.time(),
            "surfaces":  {},
            "dry_run":   self.dry_run,
        }

        surfaces = ["empty_state", "banner", "modal", "tooltip", "success_state", "error_state"]

        conn = self._get_conn()
        if conn is None:
            return {"status": "error", "reason": "db_unavailable"}

        try:
            for surface in surfaces:
                stats["surfaces"][surface] = self._evolve_surface(conn, surface)
            if not self.dry_run:
                conn.commit()
        finally:
            conn.close()

        return {"status": "ok", **stats}

    # -----------------------------------------------------------------------
    # Per-surface evolution
    # -----------------------------------------------------------------------

    def _evolve_surface(self, conn: Any, surface: str) -> dict:
        prompts = self._load_prompts(conn, surface)

        if len(prompts) < 3:
            return {"status": "skipped", "reason": "insufficient_prompts", "count": len(prompts)}

        # Sort by composite score
        prompts.sort(key=lambda p: p.composite_score(), reverse=True)

        n        = len(prompts)
        elite_n  = max(1, math.ceil(n * ELITE_FRACTION))
        retire_n = math.floor(n * RETIRE_FRACTION)

        elite   = prompts[:elite_n]
        retired = prompts[n - retire_n:]

        mutations   = [self._mutate(p) for p in elite]
        crossovers  = self._crossover(elite[:2]) if len(elite) >= 2 else []
        experimental = self._generate_experimental(surface)

        # Validate all new prompts
        new_prompts = [p for p in mutations + crossovers + experimental if self._validate(p)]

        # Retire bottom performers
        if not self.dry_run:
            self._retire_prompts(conn, [p.id for p in retired])
            for np in new_prompts:
                self._insert_prompt(conn, np, surface, prompts[0].generation + 1)

        return {
            "status":      "evolved",
            "total":       n,
            "elite":       elite_n,
            "retired":     retire_n,
            "new_created": len(new_prompts),
        }

    # -----------------------------------------------------------------------
    # Mutation
    # -----------------------------------------------------------------------

    def _mutate(self, parent: PromptRecord) -> dict:
        components = dict(parent.components)

        # Parameter mutation
        mutation_type = random.choice(["parameter", "tone", "instruction", "structural"])

        if mutation_type == "parameter":
            field = random.choice(["emotional_weight", "brevity_bias"])
            delta = random.uniform(-0.1, 0.1)
            components[field] = round(max(0.0, min(1.0, components.get(field, 0.5) + delta)), 3)

        elif mutation_type == "tone":
            components["tone"] = random.choice(MUTATION_TONES)

        elif mutation_type == "instruction":
            components["instruction_style"] = random.choice(MUTATION_STYLES)

        elif mutation_type == "structural":
            components["value_emphasis"] = random.choice(MUTATION_EMPHASIS)

        template = self._render_template(components)

        return {
            "parent_id":  parent.id,
            "components": components,
            "template":   template,
            "mutation":   mutation_type,
        }

    # -----------------------------------------------------------------------
    # Crossover
    # -----------------------------------------------------------------------

    def _crossover(self, parents: list[PromptRecord]) -> list[dict]:
        if len(parents) < 2:
            return []
        a, b = parents[0], parents[1]

        # Take value structure from A, emotional style from B
        child_components = dict(a.components)
        child_components["tone"]            = b.components.get("tone", child_components.get("tone"))
        child_components["emotional_weight"] = b.components.get("emotional_weight",
                                                                  child_components.get("emotional_weight", 0.5))

        template = self._render_template(child_components)
        return [{
            "parent_id":  a.id,
            "components": child_components,
            "template":   template,
            "mutation":   "crossover",
        }]

    # -----------------------------------------------------------------------
    # Experimental generation
    # -----------------------------------------------------------------------

    def _generate_experimental(self, surface: str) -> list[dict]:
        """Generate diverse exploration prompts to prevent mode collapse."""
        prompts = []
        for tone in random.sample(MUTATION_TONES, min(2, len(MUTATION_TONES))):
            for style in random.sample(MUTATION_STYLES, 1):
                components = {
                    "instruction_style": style,
                    "tone":              tone,
                    "value_emphasis":    random.choice(MUTATION_EMPHASIS),
                    "emotional_weight":  round(random.uniform(0.3, 0.8), 2),
                    "brevity_bias":      round(random.uniform(0.2, 0.7), 2),
                    "cta_style":         "directive" if surface != "tooltip" else "none",
                }
                prompts.append({
                    "parent_id":  None,
                    "components": components,
                    "template":   self._render_template(components),
                    "mutation":   "experimental",
                })
        return prompts

    # -----------------------------------------------------------------------
    # Safety validation (anti-degradation)
    # -----------------------------------------------------------------------

    def _validate(self, prompt: dict) -> bool:
        """Reject mutations that violate guardrails (§4.3.7)."""
        template = prompt.get("template", "").lower()

        # Anti-hype
        hype_phrases = ["revolutionize", "game-changer", "massive gains", "guaranteed"]
        if any(h in template for h in hype_phrases):
            return False

        # Must have outcome framing
        outcome_words = ["your", "you", "get", "see", "save", "track", "automat", "live"]
        if not any(ow in template for ow in outcome_words):
            return False

        return True

    # -----------------------------------------------------------------------
    # DB helpers
    # -----------------------------------------------------------------------

    def _get_conn(self):
        if not HAS_PSYCOPG2:
            return None
        try:
            return psycopg2.connect(DATABASE_URL)
        except Exception:
            return None

    def _load_prompts(self, conn, surface: str) -> list[PromptRecord]:
        with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
            cur.execute(
                "SELECT * FROM atlas_prompts WHERE surface = %s AND status IN ('active','elite') ORDER BY avg_ts DESC",
                (surface,),
            )
            rows = cur.fetchall()
        return [
            PromptRecord(
                id=r["id"], surface=r["surface"],
                components=r["components"] if isinstance(r["components"], dict) else json.loads(r["components"]),
                template=r["template"], generation=r["generation"],
                avg_ts=float(r["avg_ts"]), avg_ctr=float(r["avg_ctr"]),
                avg_conversion=float(r["avg_conversion"]), stability=float(r["stability"]),
                impressions=int(r["impressions"])
            )
            for r in rows
        ]

    def _retire_prompts(self, conn, ids: list[str]) -> None:
        if not ids:
            return
        with conn.cursor() as cur:
            cur.execute(
                "UPDATE atlas_prompts SET status = 'retired', updated_at = now() WHERE id = ANY(%s)",
                (ids,),
            )

    def _insert_prompt(self, conn, prompt: dict, surface: str, generation: int) -> None:
        new_id = str(uuid.uuid4())
        with conn.cursor() as cur:
            cur.execute(
                """INSERT INTO atlas_prompts
                   (id, surface, components, template, status, generation, parent_prompt_id,
                    avg_ts, avg_ctr, avg_conversion, stability, impressions,
                    created_at, updated_at)
                   VALUES (%s,%s,%s,%s,'active',%s,%s, 0,0,0,0,0, now(),now())""",
                (
                    new_id, surface,
                    json.dumps(prompt["components"]),
                    prompt["template"],
                    generation,
                    prompt.get("parent_id"),
                ),
            )

    def _render_template(self, components: dict) -> str:
        """Produces a human-readable prompt template string from components."""
        tone      = components.get("tone", "confident")
        style     = components.get("instruction_style", "outcome_first")
        value_emp = components.get("value_emphasis", "quantified")
        return (
            f"You are Atlas. Lead with the future state the user will achieve. "
            f"Tone: {tone}. Framing: {style}. Emphasise: {value_emp}. "
            f"Max emotional weight: {components.get('emotional_weight', 0.6)}. "
            f"Brevity bias: {components.get('brevity_bias', 0.3)}. "
            f"CTA style: {components.get('cta_style', 'directive')}."
        )


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Atlas Prompt Evolution Cycle")
    parser.add_argument("--dry-run", action="store_true", help="Log without writing")
    args = parser.parse_args()

    engine = PromptEvolutionEngine(dry_run=args.dry_run)
    result = engine.run()
    print(json.dumps(result, indent=2))
