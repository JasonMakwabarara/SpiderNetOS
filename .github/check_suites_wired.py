#!/usr/bin/env python3
"""Fail when a test suite exists that no CI job runs.

Four suites in this repository had never executed in CI. Nothing detected that,
because nothing was looking: a test directory and a workflow are separate
artefacts, and neither knows about the other. This closes that gap.

Every tests/ directory must appear in .github/test-suites.yaml, either against a
workflow job that exists, or as `unwired` with a reason, an owner and a review
date. Adding a suite and forgetting to wire it is then a red build rather than a
discovery two years later.

    python3 .github/check_suites_wired.py
"""

from __future__ import annotations

import sys
from pathlib import Path

import yaml

SKIP = {"node_modules", "vendor", ".git", "dist", "build", "__pycache__", ".venv"}
WORKFLOWS = Path(".github/workflows")
MANIFEST = Path(".github/test-suites.yaml")


def discover(root: Path = Path(".")) -> set[str]:
    """Every directory named `tests` that holds at least one test file."""
    found = set()
    for path in root.rglob("tests"):
        if not path.is_dir() or any(part in SKIP for part in path.parts):
            continue
        has_tests = any(
            f.suffix in {".php", ".py", ".js", ".ts"} and (
                f.name.startswith("test_") or f.name.endswith(("Test.php", "_test.py", ".test.js", ".spec.js"))
            )
            for f in path.rglob("*") if f.is_file()
        )
        if has_tests:
            found.add(path.as_posix().lstrip("./"))
    return found


def jobs_in(workflow: str) -> set[str]:
    path = WORKFLOWS / workflow
    if not path.exists():
        return set()
    return set((yaml.safe_load(path.read_text(encoding="utf-8")) or {}).get("jobs", {}) or {})


def main() -> int:
    if not MANIFEST.exists():
        print(f"{MANIFEST} is missing", file=sys.stderr)
        return 2

    manifest = yaml.safe_load(MANIFEST.read_text(encoding="utf-8")) or {}
    declared = {s["path"]: s for s in manifest.get("suites", [])}
    on_disk = discover()

    problems: list[str] = []

    for path in sorted(on_disk - set(declared)):
        problems.append(f"{path} holds tests and is not in {MANIFEST} - wire it to a job, or declare it unwired with a reason")

    for path in sorted(set(declared) - on_disk):
        problems.append(f"{path} is listed in {MANIFEST} but holds no tests — delete the entry")

    for path, suite in sorted(declared.items()):
        if path not in on_disk:
            continue
        runs_in, unwired = suite.get("runs_in"), suite.get("unwired")
        if not runs_in and not unwired:
            problems.append(f"{path}: needs either runs_in or unwired")
            continue
        if unwired:
            missing = [k for k in ("reason", "owner", "review_date") if not unwired.get(k)]
            if missing:
                problems.append(f"{path}: unwired is missing {', '.join(missing)}")
        for ref in runs_in or []:
            wf, job = ref.get("workflow", ""), ref.get("job", "")
            available = jobs_in(wf)
            if not available:
                problems.append(f"{path}: names workflow {wf}, which does not exist or defines no jobs")
            elif job not in available:
                problems.append(f"{path}: names job '{job}' in {wf}, which has no such job (has: {', '.join(sorted(available))})")

    wired = sum(1 for s in declared.values() if s.get("runs_in"))
    print(f"{len(on_disk)} test suites on disk, {wired} wired to a CI job, "
          f"{len(declared) - wired} declared unwired")

    if problems:
        print("\nPROBLEMS:")
        for p in problems:
            print(f"  {p}")
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
