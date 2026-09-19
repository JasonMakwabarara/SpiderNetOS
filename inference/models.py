"""
SpiderNet OS — Inference Plane Pydantic Models
"""
from typing import List, Optional

from pydantic import BaseModel, Field


class InferenceRequest(BaseModel):
    prompt: str
    system_prompt: Optional[str] = None
    model: Optional[str] = None
    tenant_id: Optional[int] = None
    cost_ceiling: float = Field(default=50.0, description="Max cost allowed for this request in USD")
    latency_max: Optional[float] = Field(default=None, description="Max acceptable latency in seconds")
    tenant_tier: str = Field(default="starter", description="Tenant subscription tier")
    temperature: float = Field(default=0.7, ge=0.0, le=2.0)
    max_tokens: int = Field(default=2048, ge=1, le=32768)
    # Explicit provider-side model/endpoint ID (e.g. a BytePlus ModelArk
    # endpoint ID). Overrides the static MODELARK_MODEL_MAP translation —
    # set from the Laravel admin dashboard via feature flags.
    provider_model_id: Optional[str] = None
    # Optional base64-encoded images (raw base64, no data: URL prefix) for
    # vision-capable models. Backward compatible: absent => text-only request.
    images: Optional[List[str]] = None


class InferenceResponse(BaseModel):
    text: str
    model: str
    tokens_used: int
    cost: float
    latency_ms: float
    provider: str


class HealthResponse(BaseModel):
    status: str
    plane: str
    version: str
