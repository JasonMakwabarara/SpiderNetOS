#!/usr/bin/env python3
"""Fail on deferred work that nobody owns.

A comment saying a thing does not work yet is not a plan. It is a note to a
reader who may never arrive, attached to code that ships and behaves as though
it were finished — `CheckAgentPermission` is named for a check and its whole
body is `return $next($request)`; the CPL service selected actions from
`torch.randn(280)` under "In production, this would use cached state"; a test
mocked the class it was testing because "imports may not work yet", and the mock
contradicted its own assertion.

So: every marker of deferred work must appear in .github/deferred.yaml with what
it actually means, what finishing it takes, an owner and a date. New debt is a
red build. Debt that has been paid off leaves a stale entry, which is also a red
build, so the register cannot drift from the code.

    python3 .github/check_deferred.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

import yaml

REGISTER = Path(".github/deferred.yaml")
SKIP_DIRS = {"node_modules", "vendor", ".git", "dist", "build", "__pycache__", ".venv", ".ruff_cache"}
SUFFIXES = {".php", ".py", ".js", ".ts", ".vue", ".yaml", ".yml", ".sh"}
VALID = {"DEFERRED", "FALSE_POSITIVE"}
SELF = {".github/check_deferred.py", ".github/deferred.yaml"}

# Word-bounded so "Todorov", "reward hacking" and "TodoWrite" are not debt.
MARKERS = re.compile(
    r"\b(TODO|FIXME|HACK|XXX)\b"
    r"|may not work"
    r"|not implemented yet"
    r"|in production,? this would"
    r"|for now,? return",
    re.IGNORECASE,
)


def scan() -> dict[str, list[tuple[int, str]]]:
    hits: dict[str, list[tuple[int, str]]] = {}
    for path in Path(".").rglob("*"):
        if not path.is_file() or path.suffix not in SUFFIXES:
            continue
        if any(part in SKIP_DIRS for part in path.parts):
            continue
        # These two exist to describe the markers; quoting one is not debt.
        if path.as_posix().removeprefix("./") in SELF:
            continue
        try:
            lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
        except OSError:
            continue
        found = [(n, line.strip()[:120]) for n, line in enumerate(lines, 1) if MARKERS.search(line)]
        if found:
            hits[path.as_posix().removeprefix("./")] = found
    return hits


def main() -> int:
    if not REGISTER.exists():
        print(f"{REGISTER} is missing", file=sys.stderr)
        return 2

    register = yaml.safe_load(REGISTER.read_text(encoding="utf-8")) or {}
    declared = {d["path"]: d for d in register.get("deferrals", [])}
    hits = scan()
    problems: list[str] = []

    for path in sorted(set(hits) - set(declared)):
        problems.append(f"{path} defers work and is not in {REGISTER}:")
        for line_no, text in hits[path][:3]:
            problems.append(f"      line {line_no}: {text}")

    for path in sorted(set(declared) - set(hits)):
        problems.append(f"{path} is in {REGISTER} but defers nothing any more - delete the entry")

    for path, entry in sorted(declared.items()):
        if path not in hits:
            continue
        missing = [k for k in ("classification", "impact", "owner", "review_date") if not entry.get(k)]
        if entry.get("classification") == "DEFERRED" and not entry.get("what_it_takes"):
            missing.append("what_it_takes")
        if missing:
            problems.append(f"{path}: missing {', '.join(missing)}")
        if entry.get("classification") not in VALID:
            problems.append(f"{path}: classification {entry.get('classification')!r} is not one of {sorted(VALID)}")

    real = sum(1 for d in declared.values() if d.get("classification") == "DEFERRED")
    print(f"{len(hits)} files carry a deferred-work marker, {len(declared)} registered "
          f"({real} real, {len(declared) - real} false positives)")

    if problems:
        print("\nPROBLEMS:")
        for p in problems:
            print(f"  {p}")
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
