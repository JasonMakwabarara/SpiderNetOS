"""
SpiderNet OS — Policy-Based Inference Router
Routes requests based on cost ceiling, latency requirements,
tenant tier, and historical model success rates.
"""
import asyncio
import time
from typing import List

import httpx

from models import InferenceRequest, InferenceResponse
from config import MODEL_COST_TABLE, OLLAMA_URL, OPENAI_API_KEY
from cost import record_usage
from metrics import record_success, record_failure, get_success_rates


class RoutingDecision:
    def __init__(self, primary: str, fallbacks: List[str], retry_count: int = 2, retry_delay_ms: int = 500):
        self.primary = primary
        self.fallbacks = fallbacks
        self.retry_count = retry_count
        self.retry_delay_ms = retry_delay_ms


def rank_models(cost_ceiling: float, latency_max: float | None, tenant_tier: str) -> List[str]:
    """Rank available models by policy constraints."""
    candidates = []

    for model_name, info in MODEL_COST_TABLE.items():
        if latency_max and info["latency_avg_ms"] > latency_max * 1000:
            continue

        if info["provider"] == "openai" and not OPENAI_API_KEY:
            continue

        candidates.append((model_name, info))

    if tenant_tier == "enterprise":
        candidates.sort(key=lambda x: (-x[1]["latency_avg_ms"], x[1]["cost_per_1k_tokens"]))
    else:
        candidates.sort(key=lambda x: (x[1]["cost_per_1k_tokens"], x[1]["latency_avg_ms"]))

    return [c[0] for c in candidates]


def route(request: InferenceRequest) -> RoutingDecision:
    """Determine routing decision based on policy evaluation."""
    if request.cost_ceiling <= 0:
        raise ValueError("Budget exhausted — CostGovernor blocked this request")

    candidates = rank_models(
        cost_ceiling=request.cost_ceiling,
        latency_max=request.latency_max,
        tenant_tier=request.tenant_tier,
    )

    if not candidates:
        raise ValueError("No models available for given constraints")

    if request.model and request.model in MODEL_COST_TABLE:
        fallbacks = [m for m in candidates if m != request.model]
        return RoutingDecision(
            primary=request.model,
            fallbacks=fallbacks,
            retry_count=2,
            retry_delay_ms=500,
        )

    return RoutingDecision(
        primary=candidates[0],
        fallbacks=candidates[1:],
        retry_count=2,
        retry_delay_ms=500,
    )


async def call_model(model: str, request: InferenceRequest) -> InferenceResponse:
    """Call a specific model provider using async httpx."""
    info = MODEL_COST_TABLE[model]
    start = time.time()

    async with httpx.AsyncClient() as client:
        if info["provider"] == "ollama":
            resp = await client.post(
                f"{OLLAMA_URL}/api/generate",
                json={
                    "model": model,
                    "prompt": request.prompt,
                    "system": request.system_prompt or "",
                    "stream": False,
                    "options": {"temperature": request.temperature, "num_predict": request.max_tokens},
                },
                timeout=120.0,
            )
            resp.raise_for_status()
            data = resp.json()
            text = data.get("response", "")
            tokens = data.get("eval_count", len(text.split()))

        elif info["provider"] == "openai":
            messages = []
            if request.system_prompt:
                messages.append({"role": "system", "content": request.system_prompt})
            messages.append({"role": "user", "content": request.prompt})

            resp = await client.post(
                "https://api.openai.com/v1/chat/completions",
                headers={"Authorization": f"Bearer {OPENAI_API_KEY}"},
                json={
                    "model": model,
                    "messages": messages,
                    "temperature": request.temperature,
                    "max_tokens": request.max_tokens,
                },
                timeout=60.0,
            )
            resp.raise_for_status()
            data = resp.json()
            text = data["choices"][0]["message"]["content"]
            tokens = data["usage"]["total_tokens"]
        else:
            raise ValueError(f"Unknown provider: {info['provider']}")

    latency_ms = (time.time() - start) * 1000
    cost = (tokens / 1000) * info["cost_per_1k_tokens"]

    return InferenceResponse(
        text=text,
        model=model,
        tokens_used=tokens,
        cost=round(cost, 6),
        latency_ms=round(latency_ms, 1),
        provider=info["provider"],
    )


async def route_request(request: InferenceRequest) -> InferenceResponse:
    """Route an inference request through the policy engine with fallback cascade."""
    decision = route(request)

    models_to_try = [decision.primary] + decision.fallbacks
    last_error = None

    for model in models_to_try:
        for attempt in range(decision.retry_count + 1):
            try:
                response = await call_model(model, request)
                await record_usage(request.tenant_id, response)
                await record_success(model)
                return response
            except Exception as e:
                last_error = e
                await record_failure(model, str(e))
                if attempt < decision.retry_count:
                    await asyncio.sleep(decision.retry_delay_ms / 1000)

    raise RuntimeError(f"All models failed. Last error: {last_error}")
