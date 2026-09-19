#!/usr/bin/env python3
"""Fail CI on any semgrep finding that nobody has accounted for.

Semgrep decides what it can see; this decides what we have agreed to live with.
A finding passes only if security/exceptions.yaml carries an entry whose rule id
matches and whose locations cover the file (and line, when one is given).

It also fails on an entry that matches nothing, because a stale exception is how
a suppression outlives the thing it was suppressing — quietly widening what CI
will accept.

    python3 security/check_findings.py semgrep.sarif security/exceptions.yaml
"""

from __future__ import annotations

import fnmatch
import json
import sys
from pathlib import Path

import yaml

VALID = {"TRUE_POSITIVE", "FALSE_POSITIVE", "ACCEPTED_RISK", "OBSOLETE_RULE"}


def findings(sarif_path: Path) -> list[tuple[str, str, int]]:
    """(rule_id, path, line) for every result in the SARIF file."""
    doc = json.loads(sarif_path.read_text(encoding="utf-8"))
    out = []
    for run in doc.get("runs", []):
        for res in run.get("results", []):
            loc = res["locations"][0]["physicalLocation"]
            out.append((
                res.get("ruleId", "?"),
                loc["artifactLocation"]["uri"].replace("\\", "/"),
                int(loc["region"].get("startLine", 0)),
            ))
    return out


def covers(pattern: str, path: str, line: int) -> bool:
    """`dir/*.py`, `dir/file.py` or `dir/file.py:12`; globs allowed in the path."""
    pattern = pattern.strip().replace("\\", "/")
    want_line = None
    head, sep, tail = pattern.rpartition(":")
    if sep and tail.isdigit():
        pattern, want_line = head, int(tail)
    if want_line is not None and want_line != line:
        return False
    return fnmatch.fnmatch(path, pattern)


def main() -> int:
    sarif = Path(sys.argv[1] if len(sys.argv) > 1 else "semgrep.sarif")
    registry = Path(sys.argv[2] if len(sys.argv) > 2 else "security/exceptions.yaml")

    if not sarif.exists():
        print(f"no SARIF at {sarif}; the scan did not produce one", file=sys.stderr)
        return 2

    entries = (yaml.safe_load(registry.read_text(encoding="utf-8")) or {}).get("exceptions", [])

    problems: list[str] = []
    for entry in entries:
        missing = [k for k in ("rule_id", "locations", "classification", "reason", "owner", "review_date") if not entry.get(k)]
        if missing:
            problems.append(f"exception for {entry.get('rule_id', '?')} is missing: {', '.join(missing)}")
        if entry.get("classification") not in VALID:
            problems.append(f"{entry.get('rule_id', '?')}: classification {entry.get('classification')!r} is not one of {sorted(VALID)}")

    used = {id(e): False for e in entries}
    uncovered = []

    for rule, path, line in findings(sarif):
        for entry in entries:
            if entry.get("rule_id") != rule:
                continue
            if any(covers(p, path, line) for p in entry.get("locations", [])):
                used[id(entry)] = True
                break
        else:
            uncovered.append((rule, path, line))

    total = len(findings(sarif))
    print(f"{total} findings, {total - len(uncovered)} accounted for, {len(uncovered)} not")

    if uncovered:
        print("\nNOT ACCOUNTED FOR - fix it, or add an entry to security/exceptions.yaml:")
        for rule, path, line in sorted(uncovered):
            print(f"  {path}:{line}  {rule}")

    stale = [e["rule_id"] for e in entries if not used[id(e)]]
    if stale:
        print("\nSTALE - these exceptions matched nothing; delete them:")
        for rule in stale:
            print(f"  {rule}")

    if problems:
        print("\nMALFORMED:")
        for p in problems:
            print(f"  {p}")

    return 1 if (uncovered or stale or problems) else 0


if __name__ == "__main__":
    raise SystemExit(main())
