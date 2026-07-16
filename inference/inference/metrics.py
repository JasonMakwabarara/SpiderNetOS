"""
SpiderNet OS — Per-Model Success Rate Tracking
Tracks success/failure rates per model for policy routing optimization.
"""
import redis.asyncio as aioredis

from config import REDIS_URL

_redis = None


async def get_redis():
    global _redis
    if _redis is None:
        _redis = aioredis.from_url(REDIS_URL, decode_responses=True)
    return _redis


async def record_success(model: str):
    """Record a successful inference for a model."""
    r = await get_redis()
    await r.hincrby(f"spidernet:model_metrics:{model}", "success", 1)


async def record_failure(model: str, error: str = ""):
    """Record a failed inference for a model."""
    r = await get_redis()
    await r.hincrby(f"spidernet:model_metrics:{model}", "failure", 1)


async def get_success_rate(model: str) -> float:
    """Get success rate for a model (0.0 to 1.0)."""
    r = await get_redis()
    data = await r.hgetall(f"spidernet:model_metrics:{model}")
    
    success = int(data.get("success", 0))
    failure = int(data.get("failure", 0))
    total = success + failure
    
    if total == 0:
        return 1.0
    
    return success / total


async def get_success_rates() -> dict:
    """Alias for get_all_success_rates."""
    return await get_all_success_rates()


async def get_all_success_rates() -> dict:
    """Get success rates for all models."""
    r = await get_redis()
    rates = {}
    
    async for key in r.scan_iter("spidernet:model_metrics:*"):
        model = key.split(":")[-1]
        rates[model] = await get_success_rate(model)
    
    return rates
