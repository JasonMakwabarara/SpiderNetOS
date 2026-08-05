#!/usr/bin/env python3
"""Evaluate SFT/preference JSONL bundles and enforce quality gates."""

from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path
from statistics import mean


def read_jsonl(path: Path) -> list[dict]:
    rows: list[dict] = []
    if not path.exists():
        return rows
    with path.open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            rows.append(json.loads(line))
    return rows


def ratio(numerator: int, denominator: int) -> float:
    return 0.0 if denominator == 0 else numerator / denominator


def dist(values: list[int]) -> dict[str, int]:
    c = Counter(values)
    return {str(i): c.get(i, 0) for i in range(5)}


def main() -> None:
    parser = argparse.ArgumentParser(description="Evaluate training data quality and apply gates.")
    parser.add_argument("--sft", required=True, help="Path to sft.jsonl")
    parser.add_argument("--preference", required=True, help="Path to preference.jsonl")
    parser.add_argument("--min-quality", type=int, default=2)
    parser.add_argument("--min-sft-rows", type=int, default=20)
    parser.add_argument("--min-preference-rows", type=int, default=20)
    parser.add_argument("--min-distinct-preference-ratio", type=float, default=0.95)
    parser.add_argument("--out", default="", help="Optional output JSON path (defaults to sibling quality_gate.json).")
    args = parser.parse_args()

    sft_rows = read_jsonl(Path(args.sft))
    pref_rows = read_jsonl(Path(args.preference))

    sft_scores = [int(r.get("metadata", {}).get("quality_score", 0)) for r in sft_rows]
    pref_scores = [int(r.get("metadata", {}).get("quality_score", 0)) for r in pref_rows]
    rejected_types = [r.get("metadata", {}).get("rejected_type", "unknown") for r in pref_rows]
    distinct_pairs = sum(1 for r in pref_rows if r.get("chosen") != r.get("rejected"))

    sft_ratio = ratio(sum(1 for s in sft_scores if s >= args.min_quality), len(sft_scores))
    pref_ratio = ratio(sum(1 for s in pref_scores if s >= args.min_quality), len(pref_scores))
    distinct_ratio = ratio(distinct_pairs, len(pref_rows))

    gates = {
        "min_sft_rows": len(sft_rows) >= args.min_sft_rows,
        "min_preference_rows": len(pref_rows) >= args.min_preference_rows,
        "min_distinct_preference_ratio": distinct_ratio >= args.min_distinct_preference_ratio,
        "sft_quality_hit_ratio": sft_ratio >= 0.8,
        "preference_quality_hit_ratio": pref_ratio >= 0.8,
    }

    result = {
        "passed": all(gates.values()),
        "gates": gates,
        "metrics": {
            "sft_rows": len(sft_rows),
            "preference_rows": len(pref_rows),
            "sft_mean_quality": round(mean(sft_scores), 4) if sft_scores else 0.0,
            "preference_mean_quality": round(mean(pref_scores), 4) if pref_scores else 0.0,
            "sft_quality_distribution": dist(sft_scores),
            "preference_quality_distribution": dist(pref_scores),
            "rejected_type_distribution": dict(Counter(rejected_types)),
            "distinct_preference_ratio": round(distinct_ratio, 4),
            "sft_quality_hit_ratio": round(sft_ratio, 4),
            "preference_quality_hit_ratio": round(pref_ratio, 4),
        },
    }

    out_path = Path(args.out) if args.out else Path(args.sft).parent / "quality_gate.json"
    out_path.write_text(json.dumps(result, indent=2), encoding="utf-8")

    print(json.dumps({"passed": result["passed"], "out": str(out_path)}))
    raise SystemExit(0 if result["passed"] else 1)


if __name__ == "__main__":
    main()
