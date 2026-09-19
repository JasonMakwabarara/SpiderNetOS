"""
Atlas / SpiderNet OS — Feature Flag Client (Python)

Mirrors the PHP FeatureFlag service resolution chain:
  1. Redis per-tenant key  feature:<name>:tenant:<tenant_id>
  2. Redis global key      feature:<name>
  3. Environment variable  FEATURE_<UPPER_SNAKE>
  4. Hard-coded defaults   (see _DEFAULTS below)

Results are cached in-process for TTL_SECONDS (5 s) to limit Redis RTT.

Usage:
    from intelligence.atlas.feature_flag import FeatureFlag

    if FeatureFlag.on("atlas.usage_aggregates_v2"):
        ...

    if FeatureFlag.on("atlas.copy.empty_state", tenant_id="abc-123"):
        ...

    temp = FeatureFlag.value("atlas.bandit.temperature")  # float
"""

from __future__ import annotations

import os
import threading
import time
from typing import Any

import redis as _redis_pkg

# ---------------------------------------------------------------------------
# Connection
# ---------------------------------------------------------------------------

_REDIS_URL = os.getenv("REDIS_URL", "redis://redis:6379/0")

try:
    _redis: _redis_pkg.Redis = _redis_pkg.from_url(_REDIS_URL, decode_responses=True)
except Exception:  # pragma: no cover
    _redis = None  # type: ignore[assignment]

# ---------------------------------------------------------------------------
# Hard-coded defaults (keep in sync with backend/config/features.php)
# ---------------------------------------------------------------------------

_DEFAULTS: dict[str, Any] = {
    "atlas.usage_aggregates_v2":             "off",
    "atlas.usage_aggregates_v2.shadow":      "off",
    "atlas.usage_aggregates_v2.cutover":     "off",
    "atlas.usage_aggregates_v2.rollback":    "off",
    "atlas.copy.empty_state":                "on",
    "atlas.copy.banner":                     "on",
    "atlas.copy.modal":                      "on",
    "atlas.copy.tooltip":                    "on",
    "atlas.copy.success_state":              "on",
    "atlas.copy.error_state":                "on",
    "atlas.prompt_evolution":                "off",
    "atlas.bandit.algo":                     "thompson",
    "atlas.bandit.temperature":              1.0,
    "atlas.bandit.min_impressions_floor":    50,
    # State Transition Engine (plan §12)
    "platform.ste_read":                     "on",
    "platform.ste_projector":                "on",
    "platform.ste_simulate":                 "on",
    "platform.ste_control":                  "off",
    # Atlas Enhance Prompt
    "atlas.enhance_prompt":                  "on",
}

# ---------------------------------------------------------------------------
# In-process cache
# ---------------------------------------------------------------------------

TTL_SECONDS: float = 5.0

_cache: dict[str, tuple[Any, float]] = {}
_lock = threading.Lock()


def _cache_get(key: str) -> tuple[bool, Any]:
    """Return (hit, value)."""
    with _lock:
        entry = _cache.get(key)
    if entry is None:
        return False, None
    val, exp = entry
    if time.monotonic() > exp:
        return False, None
    return True, val


def _cache_set(key: str, val: Any) -> None:
    with _lock:
        _cache[key] = (val, time.monotonic() + TTL_SECONDS)


def _cache_bust(key: str) -> None:
    with _lock:
        _cache.pop(key, None)


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------

class FeatureFlag:
    """Static-method API, mirroring the PHP service."""

    @staticmethod
    def on(name: str, tenant_id: str | None = None) -> bool:
        """Return True when the flag resolves to "on"."""
        val = FeatureFlag.value(name, tenant_id)
        if isinstance(val, bool):
            return val
        return str(val).lower() == "on"

    @staticmethod
    def fallback(name: str) -> bool:
        """Return True when a copy-surface flag resolves to "fallback"."""
        val = FeatureFlag.value(name)
        return str(val).lower() == "fallback"

    @staticmethod
    def value(name: str, tenant_id: str | None = None) -> Any:
        """Return the raw resolved flag value."""
        cache_key = f"featureflag:{name}" + (f":t:{tenant_id}" if tenant_id else "")
        hit, cached = _cache_get(cache_key)
        if hit:
            return cached

        resolved = _resolve(name, tenant_id)
        _cache_set(cache_key, resolved)
        return resolved

    @staticmethod
    def set(name: str, value: str, tenant_id: str | None = None) -> None:
        """Force-set a Redis override and bust the local cache."""
        redis_key = (
            f"feature:{name}:tenant:{tenant_id}" if tenant_id else f"feature:{name}"
        )
        if _redis is not None:
            try:
                _redis.set(redis_key, value)
            except Exception:  # pragma: no cover
                pass
        cache_key = f"featureflag:{name}" + (f":t:{tenant_id}" if tenant_id else "")
        _cache_bust(cache_key)

    @staticmethod
    def forget(name: str, tenant_id: str | None = None) -> None:
        """Remove a Redis override and bust the local cache."""
        redis_key = (
            f"feature:{name}:tenant:{tenant_id}" if tenant_id else f"feature:{name}"
        )
        if _redis is not None:
            try:
                _redis.delete(redis_key)
            except Exception:  # pragma: no cover
                pass
        cache_key = f"featureflag:{name}" + (f":t:{tenant_id}" if tenant_id else "")
        _cache_bust(cache_key)

    @staticmethod
    def all() -> dict[str, Any]:
        """Return all known flags and their current resolved values."""
        return {name: FeatureFlag.value(name) for name in _DEFAULTS}


# ---------------------------------------------------------------------------
# Private resolution
# ---------------------------------------------------------------------------

def _resolve(name: str, tenant_id: str | None) -> Any:
    # 1. Per-tenant Redis override
    if tenant_id and _redis is not None:
        try:
            val = _redis.get(f"feature:{name}:tenant:{tenant_id}")
            if val is not None:
                return _coerce(name, val)
        except Exception:
            pass

    # 2. Global Redis override
    if _redis is not None:
        try:
            val = _redis.get(f"feature:{name}")
            if val is not None:
                return _coerce(name, val)
        except Exception:
            pass

    # 3. Environment variable  FEATURE_ATLAS_USAGE_AGGREGATES_V2 etc.
    env_key = "FEATURE_" + name.upper().replace(".", "_")
    env_val = os.getenv(env_key)
    if env_val is not None:
        return _coerce(name, env_val)

    # 4. Hard-coded default
    return _DEFAULTS.get(name, "off")


def _coerce(name: str, raw: str) -> Any:
    """Cast string values to appropriate Python types based on known defaults."""
    default = _DEFAULTS.get(name)
    if isinstance(default, float):
        try:
            return float(raw)
        except ValueError:
            return default
    if isinstance(default, int):
        try:
            return int(raw)
        except ValueError:
            return default
    return raw
