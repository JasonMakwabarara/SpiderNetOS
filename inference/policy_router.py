"""
SpiderNet OS — Policy-Based Inference Router
Routes requests based on cost ceiling, latency requirements,
tenant tier, and historical model success rates.
"""
import asyncio
import time
from typing import List

import httpx
from config import (
    DEEPSEEK_API_KEY,
    DEEPSEEK_BASE_URL,
    MODEL_COST_TABLE,
    MODELARK_MODEL_MAP,
    PROVIDER_PRIORITY,
    PROVIDER_PRIORITY_DEFAULT,
    OLLAMA_ENABLED,
    OLLAMA_URL,
    OPENAI_API_KEY,
)
from cost import record_usage
from metrics import record_failure, record_success
from models import InferenceRequest, InferenceResponse


class RoutingDecision:
    def __init__(self, primary: str, fallbacks: List[str], retry_count: int = 2, retry_delay_ms: int = 500):
        self.primary = primary
        self.fallbacks = fallbacks
        self.retry_count = retry_count
        self.retry_delay_ms = retry_delay_ms


def rank_models(cost_ceiling: float, latency_max: float | None, tenant_tier: str,
                require_capability: str | None = None) -> List[str]:
    """Rank available models by policy constraints.

    require_capability filters out models whose "capabilities" list does not
    include the given capability (e.g. "vision" for image inputs).
    """
    candidates = []

    for model_name, info in MODEL_COST_TABLE.items():
        if latency_max and info["latency_avg_ms"] > latency_max * 1000:
            continue

        if require_capability and require_capability not in info.get("capabilities", []):
            continue

        if info["provider"] == "openai" and not OPENAI_API_KEY:
            continue

        if info["provider"] == "modelark" and not DEEPSEEK_API_KEY:
            continue

        if info["provider"] == "ollama" and not OLLAMA_ENABLED:
            continue

        candidates.append((model_name, info))

    def _priority(info: dict) -> int:
        return PROVIDER_PRIORITY.get(info["provider"], PROVIDER_PRIORITY_DEFAULT)

    # Provider preference first (local Ollama → ModelArk DeepSeek → OpenAI),
    # then the tier's cost/latency policy within each provider tier.
    if tenant_tier == "enterprise":
        candidates.sort(key=lambda x: (_priority(x[1]), -x[1]["latency_avg_ms"], x[1]["cost_per_1k_tokens"]))
    else:
        candidates.sort(key=lambda x: (_priority(x[1]), x[1]["cost_per_1k_tokens"], x[1]["latency_avg_ms"]))

    return [c[0] for c in candidates]


def route(request: InferenceRequest, require_capability: str | None = None) -> RoutingDecision:
    """Determine routing decision based on policy evaluation."""
    if request.cost_ceiling <= 0:
        raise ValueError("Budget exhausted — CostGovernor blocked this request")

    candidates = rank_models(
        cost_ceiling=request.cost_ceiling,
        latency_max=request.latency_max,
        tenant_tier=request.tenant_tier,
        require_capability=require_capability,
    )

    if not candidates:
        raise ValueError("No models available for given constraints")

    if request.model and request.model in MODEL_COST_TABLE:
        pinned_info = MODEL_COST_TABLE[request.model]
        # A pinned model that lacks the required capability is ignored
        # (falling through to the capability-filtered candidate ranking).
        if not require_capability or require_capability in pinned_info.get("capabilities", []):
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


def _sniff_image_mime(b64: str) -> str:
    """Best-effort MIME detection from base64 magic-byte prefixes."""
    if b64.startswith("/9j/"):
        return "image/jpeg"
    if b64.startswith("iVBOR"):
        return "image/png"
    if b64.startswith("R0lGOD"):
        return "image/gif"
    if b64.startswith("UklGR"):
        return "image/webp"
    return "image/png"


async def call_model(model: str, request: InferenceRequest) -> InferenceResponse:
    """Call a specific model provider using async httpx."""
    info = MODEL_COST_TABLE[model]
    start = time.time()

    async with httpx.AsyncClient() as client:
        if info["provider"] == "ollama":
            body = {
                "model": model,
                "prompt": request.prompt,
                "system": request.system_prompt or "",
                "stream": False,
                "options": {"temperature": request.temperature, "num_predict": request.max_tokens},
            }
            if request.images:
                # Ollama expects raw base64 strings (no data: URL prefix).
                body["images"] = request.images
            resp = await client.post(
                f"{OLLAMA_URL}/api/generate",
                json=body,
                timeout=120.0,
            )
            resp.raise_for_status()
            data = resp.json()
            text = data.get("response", "")
            tokens = data.get("eval_count", len(text.split()))

        elif info["provider"] in ("openai", "modelark"):
            messages = []
            if request.system_prompt:
                messages.append({"role": "system", "content": request.system_prompt})
            if request.images:
                # OpenAI-compatible multimodal content parts with data: URLs.
                parts = [{"type": "text", "text": request.prompt}]
                for image_b64 in request.images:
                    mime = _sniff_image_mime(image_b64)
                    parts.append({
                        "type": "image_url",
                        "image_url": {"url": f"data:{mime};base64,{image_b64}"},
                    })
                messages.append({"role": "user", "content": parts})
            else:
                messages.append({"role": "user", "content": request.prompt})

            if info["provider"] == "modelark":
                # BytePlus ModelArk is OpenAI-compatible but addresses models
                # by its own model/endpoint IDs (Hannah's endpoint-map pattern).
                url = f"{DEEPSEEK_BASE_URL.rstrip('/')}/chat/completions"
                api_key = DEEPSEEK_API_KEY
                wire_model = request.provider_model_id or MODELARK_MODEL_MAP.get(model, model)
            else:
                url = "https://api.openai.com/v1/chat/completions"
                api_key = OPENAI_API_KEY
                wire_model = model

            resp = await client.post(
                url,
                headers={"Authorization": f"Bearer {api_key}"},
                json={
                    "model": wire_model,
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


async def route_request(request: InferenceRequest, require_capability: str | None = None) -> InferenceResponse:
    """Route an inference request through the policy engine with fallback cascade."""
    decision = route(request, require_capability=require_capability)

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
