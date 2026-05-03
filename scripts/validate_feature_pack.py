#!/usr/bin/env python3
"""Structural validation for SpiderNet Feature Pack pack.yaml manifests.

Requires PyYAML (`pip install pyyaml`).
Intended parity with docs in packages/feature-packs/schema/feature-pack.schema.json (subset).
"""

from __future__ import annotations

import sys
from pathlib import Path

_REQUIRED_TOP = ("apiVersion", "kind", "metadata", "spec")
_REQUIRED_META = ("id", "version", "vertical")
_AGENT_FIELDS = ("id", "displayName", "capabilities")


def _die(msg: str) -> None:
    print(msg, file=sys.stderr)
    raise SystemExit(1)


def main() -> None:
    try:
        import yaml  # type: ignore
    except ImportError:
        _die("PyYAML missing. Install with: pip install pyyaml")

    if len(sys.argv) < 2:
        _die("Usage: validate_feature_pack.py <path-to-pack.yaml>")

    path = Path(sys.argv[1])
    if not path.is_file():
        _die(f"Not a file: {path}")

    data = yaml.safe_load(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        _die("Root must be a mapping")

    for key in _REQUIRED_TOP:
        if key not in data:
            _die(f"Missing required key: {key}")

    if data.get("apiVersion") != "spidernet/v1":
        _die('apiVersion must be "spidernet/v1"')
    if data.get("kind") != "FeaturePack":
        _die('kind must be "FeaturePack"')

    md = data.get("metadata") or {}
    if not isinstance(md, dict):
        _die("metadata must be an object")
    for k in _REQUIRED_META:
        if k not in md:
            _die(f"metadata missing {k}")

    spec = data.get("spec") or {}
    if not isinstance(spec, dict):
        _die("spec must be an object")
    req = spec.get("requires") or {}
    prv = spec.get("provides") or {}
    if not isinstance(req, dict) or not isinstance(prv, dict):
        _die("spec.requires / spec.provides must be objects")

    agents = prv.get("dynamic_agents") or []
    if not isinstance(agents, list) or len(agents) == 0:
        _die("spec.provides.dynamic_agents must be a non-empty list")

    for ag in agents:
        if not isinstance(ag, dict):
            _die("Each dynamic agent must be an object")
        for f in _AGENT_FIELDS:
            if f not in ag:
                _die(f"Dynamic agent missing {f}")

    print(f"OK: {path.name} ({md.get('id')} @{md.get('version')})")


if __name__ == "__main__":
    main()
