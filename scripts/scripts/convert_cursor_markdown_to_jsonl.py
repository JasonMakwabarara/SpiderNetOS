#!/usr/bin/env python3
"""Convert Cursor-exported markdown chats to SFT and preference JSONL datasets."""

from __future__ import annotations

import argparse
import json
import random
import re
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, List


TURN_MARKER_RE = re.compile(r"^\*\*(User|Cursor)\*\*\s*$", re.MULTILINE)


@dataclass
class Turn:
    role: str
    text: str


def parse_turns(markdown_text: str) -> List[Turn]:
    turns: List[Turn] = []
    markers = list(TURN_MARKER_RE.finditer(markdown_text))
    for i, marker in enumerate(markers):
        role = marker.group(1).lower()
        start = marker.end()
        end = markers[i + 1].start() if i + 1 < len(markers) else len(markdown_text)
        block = markdown_text[start:end]
        block = re.sub(r"^\s*---\s*$", "", block, flags=re.MULTILINE).strip()
        if not block:
            continue
        turns.append(Turn(role=role, text=block))
    return turns


def iter_pairs(turns: Iterable[Turn]) -> Iterable[tuple[Turn, Turn]]:
    turns = list(turns)
    for i in range(len(turns) - 1):
        if turns[i].role == "user" and turns[i + 1].role == "cursor":
            yield turns[i], turns[i + 1]


def verification_quality_score(text: str) -> int:
    lowered = text.lower()
    score = 0

    if re.search(r"\b(test|verified|verification|passed|failed|exit code|green)\b", lowered):
        score += 1
    if re.search(r"\b(php artisan|npm|pytest|curl|route:list|docker|python)\b", lowered):
        score += 1
    if re.search(r"\b(\d+/\d+|%|rows|assertions|exit_code)\b", lowered):
        score += 1
    if len(re.findall(r"`[^`]+`", text)) >= 2:
        score += 1

    return min(score, 4)


def degrade_response_rule_based(text: str) -> tuple[str, str]:
    lowered = text.lower()

    if re.search(r"\b(test|verified|passed|failed|exit code|route:list|pytest|php artisan)\b", lowered):
        lines = [ln for ln in text.splitlines() if not re.search(r"(test|pass|fail|exit|route:list|pytest|php artisan)", ln, re.I)]
        degraded = "\n".join(lines).strip()
        if degraded:
            return degraded, "missing_verification"

    if re.search(r"\b(root cause|because|fixed|updated|patched|resolved)\b", lowered):
        degraded = re.sub(
            r"\b(root cause|because|fixed|updated|patched|resolved|implemented)\b.*",
            "I reviewed this and applied a general update.",
            text,
            flags=re.I,
        ).strip()
        if degraded:
            return degraded, "vague_root_cause"

    degraded = "I made some changes that should help. Please test and confirm."
    return degraded, "generic_degraded"


def build_sft_records(pairs: Iterable[tuple[Turn, Turn]], min_quality_sft: int) -> list[dict]:
    rows: list[dict] = []
    for user_turn, assistant_turn in pairs:
        quality = verification_quality_score(assistant_turn.text)
        if quality < min_quality_sft:
            continue
        rows.append(
            {
                "messages": [{"role": "user", "content": user_turn.text}],
                "completion": assistant_turn.text,
                "metadata": {"quality_score": quality},
            }
        )
    return rows


def build_preference_records(pairs: Iterable[tuple[Turn, Turn]], min_quality: int) -> list[dict]:
    rows: list[dict] = []
    for user_turn, assistant_turn in pairs:
        quality = verification_quality_score(assistant_turn.text)
        if quality < min_quality:
            continue
        rejected, rejected_type = degrade_response_rule_based(assistant_turn.text)
        if rejected == assistant_turn.text:
            continue
        rows.append(
            {
                "prompt": user_turn.text,
                "chosen": assistant_turn.text,
                "rejected": rejected,
                "metadata": {
                    "quality_score": quality,
                    "rejected_type": rejected_type,
                },
            }
        )
    return rows


def distribution(values: Iterable[int]) -> dict:
    counts = Counter(values)
    return {str(i): counts.get(i, 0) for i in range(5)}


def write_jsonl(path: Path, rows: Iterable[dict]) -> None:
    with path.open("w", encoding="utf-8") as f:
        for row in rows:
            f.write(json.dumps(row, ensure_ascii=False) + "\n")


def main() -> None:
    parser = argparse.ArgumentParser(description="Convert Cursor markdown export to SFT/preference JSONL.")
    parser.add_argument("--input", required=True, help="Path to markdown export file.")
    parser.add_argument("--out-dir", required=True, help="Output directory.")
    parser.add_argument("--min-quality", type=int, default=0, choices=range(0, 5))
    parser.add_argument("--min-quality-sft", type=int, default=0, choices=range(0, 5))
    parser.add_argument("--quality-report", action="store_true", help="Emit quality_report.json.")
    parser.add_argument("--seed", type=int, default=42)
    args = parser.parse_args()

    random.seed(args.seed)
    in_path = Path(args.input)
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    text = in_path.read_text(encoding="utf-8")
    turns = parse_turns(text)
    pairs = list(iter_pairs(turns))

    sft_rows = build_sft_records(pairs, min_quality_sft=args.min_quality_sft)
    pref_rows = build_preference_records(pairs, min_quality=args.min_quality)

    write_jsonl(out_dir / "sft.jsonl", sft_rows)
    write_jsonl(out_dir / "preference.jsonl", pref_rows)

    if args.quality_report:
        all_assistant_scores = [verification_quality_score(t.text) for t in turns if t.role == "cursor"]
        sft_scores = [verification_quality_score(b.text) for _, b in pairs]
        pref_scores = [verification_quality_score(b.text) for _, b in pairs]
        report = {
            "summary": {
                "parsed_turns": len(turns),
                "paired_examples": len(pairs),
                "sft_rows": len(sft_rows),
                "preference_rows": len(pref_rows),
            },
            "assistant_turns": distribution(all_assistant_scores),
            "sft_candidates_pre_filter": distribution(sft_scores),
            "preference_candidates_pre_filter": distribution(pref_scores),
        }
        (out_dir / "quality_report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")

    print(
        json.dumps(
            {
                "parsed_turns": len(turns),
                "sft_rows": len(sft_rows),
                "preference_rows": len(pref_rows),
                "out_dir": str(out_dir),
            }
        )
    )


if __name__ == "__main__":
    main()
