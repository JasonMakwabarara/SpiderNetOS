"""
SpiderNet OS — Inference Cost Tracking
Pre-inference cost gate + post-inference usage recording via Redis.
"""
import json
from typing import Optional

from config import REDIS_URL
from models import InferenceResponse

import redis.asyncio as aioredis

_redis = None


async def get_redis():
    global _redis
    if _redis is None:
        _redis = aioredis.from_url(REDIS_URL, decode_responses=True)
    return _redis


async def record_usage(tenant_id: Optional[int], response: InferenceResponse):
    """Record token usage and cost for a tenant."""
    if tenant_id is None:
        return

    r = await get_redis()
    key = f"spidernet:usage:{tenant_id}"

    await r.hincrby(key, "total_tokens", response.tokens_used)
    await r.hincrbyfloat(key, "total_cost", response.cost)
    await r.hincrby(key, "request_count", 1)

    event = {
        "tenant_id": tenant_id,
        "model": response.model,
        "tokens": response.tokens_used,
        "cost": response.cost,
        "latency_ms": response.latency_ms,
        "provider": response.provider,
    }
    await r.publish("spidernet:inference_usage", json.dumps(event))


async def get_usage_summary() -> dict:
    """Get aggregated usage summary across all tenants."""
    r = await get_redis()
    keys = []
    async for key in r.scan_iter("spidernet:usage:*"):
        keys.append(key)

    total_tokens = 0
    total_cost = 0.0
    total_requests = 0

    for key in keys:
        data = await r.hgetall(key)
        total_tokens += int(data.get("total_tokens", 0))
        total_cost += float(data.get("total_cost", 0))
        total_requests += int(data.get("request_count", 0))

    return {
        "total_tokens": total_tokens,
        "total_cost": round(total_cost, 6),
        "total_requests": total_requests,
        "tenant_count": len(keys),
    }
