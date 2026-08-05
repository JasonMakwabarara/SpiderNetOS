"""
SpiderNet OS — GPU Auto-Scaling Advisor
Monitors inference usage and emits scaling recommendations.
"""
import json
from typing import Optional

import redis.asyncio as aioredis

from config import REDIS_URL

SCALE_UP_THRESHOLD = 1000
SCALE_DOWN_THRESHOLD = 100

_redis = None


async def get_redis():
    global _redis
    if _redis is None:
        _redis = aioredis.from_url(REDIS_URL, decode_responses=True)
    return _redis


async def check_scaling_recommendation() -> Optional[dict]:
    """Check if scaling action is recommended based on usage patterns."""
    r = await get_redis()

    total_requests = 0
    async for key in r.scan_iter("spidernet:usage:*"):
        data = await r.hgetall(key)
        total_requests += int(data.get("request_count", 0))

    recommendation = None
    if total_requests > SCALE_UP_THRESHOLD:
        recommendation = {
            "action": "scale_up",
            "reason": f"Monthly requests ({total_requests}) exceed threshold ({SCALE_UP_THRESHOLD})",
            "current_requests": total_requests,
        }
    elif total_requests < SCALE_DOWN_THRESHOLD:
        recommendation = {
            "action": "scale_down",
            "reason": f"Monthly requests ({total_requests}) below threshold ({SCALE_DOWN_THRESHOLD})",
            "current_requests": total_requests,
        }

    if recommendation:
        await r.publish("spidernet:events", json.dumps({
            "type": "gpu.scale.recommended",
            "payload": recommendation,
        }))

    return recommendation
