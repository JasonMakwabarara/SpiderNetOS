"""
Hermes Integration API Routes
FastAPI endpoints for Hermes Agent coordination with SpiderNetOS.

Every route except /health is server to server and requires the
`X-Internal-Key` header to match BACKEND_INTERNAL_KEY. With the key unset
those routes refuse all calls rather than run open.
"""

import hmac
import logging
import os
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, Header, HTTPException, Query
from pydantic import BaseModel, Field

from services.shared.hermes_bridge import HermesIntegrationBridge, current_bridge

logger = logging.getLogger(__name__)


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def require_internal_key(x_internal_key: Optional[str] = Header(default=None)) -> None:
    expected = os.getenv('BACKEND_INTERNAL_KEY', '')
    if not expected:
        raise HTTPException(status_code=503, detail="BACKEND_INTERNAL_KEY is not configured")
    if not x_internal_key or not hmac.compare_digest(x_internal_key, expected):
        raise HTTPException(status_code=401, detail="Invalid internal key")


def get_hermes_bridge() -> HermesIntegrationBridge:
    bridge = current_bridge()
    if bridge is None:
        raise HTTPException(status_code=503, detail="Hermes bridge is not running")
    return bridge


router = APIRouter(prefix="/api/hermes", tags=["hermes-integration"])
protected = [Depends(require_internal_key)]


class HermesCoordinationRequest(BaseModel):
    """Request from Hermes Agent for SpiderNetOS coordination."""
    tenant_id: str = Field(..., min_length=1, description="Tenant the conversation belongs to")
    message: str = Field(..., description="User message to process")
    channel: str = Field(..., description="Communication channel (voice, text, email, etc.)")
    conversation_id: str = Field(..., description="Unique conversation identifier")
    conversation_history: List[Dict[str, Any]] = Field(default_factory=list)
    user_context: Optional[Dict[str, Any]] = Field(None, description="User context and preferences")
    intent_analysis: Optional[Dict[str, Any]] = Field(None, description="Hermes intent analysis")
    metadata: Optional[Dict[str, Any]] = Field(None, description="Additional metadata")
    user_satisfaction: Optional[float] = Field(0.5, description="User satisfaction score", ge=0.0, le=1.0)


class HermesCoordinationResponse(BaseModel):
    """Response to Hermes coordination request."""
    status: str = Field(..., description="success, partial, timeout, blocked or error")
    response: str = Field(..., description="Coordinated response text")
    agents_used: List[str] = Field(default_factory=list, description="Agents that participated")
    workflow_triggers: List[str] = Field(default_factory=list, description="Workflows triggered")
    learning_signals: Dict[str, Any] = Field(default_factory=dict, description="Learning data for RL")
    execution_time_ms: float = Field(..., description="Execution time in milliseconds")
    timestamp: str = Field(default_factory=_now)


class HermesLearningSyncRequest(BaseModel):
    """Learning data sync from Hermes Agent."""
    tenant_id: str = Field(..., min_length=1)
    source: str = Field(..., description="Data source (hermes)")
    learning_type: str = Field(..., description="Type of learning data")
    entries: List[Dict[str, Any]] = Field(..., description="Learning data entries")
    sync_timestamp: str = Field(..., description="Sync timestamp")


class HermesLearningSyncResponse(BaseModel):
    """Response to learning data sync."""
    status: str = Field(..., description="Sync status")
    entries_processed: int = Field(..., description="Number of entries processed")
    timestamp: str = Field(default_factory=_now)


@router.post("/coordinate", response_model=HermesCoordinationResponse, dependencies=protected)
async def coordinate_with_spidernet(
    request: HermesCoordinationRequest,
    hermes_bridge: HermesIntegrationBridge = Depends(get_hermes_bridge),
) -> HermesCoordinationResponse:
    """Coordinate a Hermes request with the SpiderNetOS agents."""
    logger.info(
        "Hermes coordination request %s from %s channel", request.conversation_id, request.channel
    )
    result = await hermes_bridge.coordinate_request(request.model_dump())
    return HermesCoordinationResponse(**{
        key: result[key]
        for key in HermesCoordinationResponse.model_fields
        if key in result
    })


@router.post("/learning/sync", response_model=HermesLearningSyncResponse, dependencies=protected)
async def sync_hermes_learning(
    request: HermesLearningSyncRequest,
    hermes_bridge: HermesIntegrationBridge = Depends(get_hermes_bridge),
) -> HermesLearningSyncResponse:
    """Store learning data from Hermes for SpiderNetOS training."""
    try:
        processed = await hermes_bridge.ingest_learning(
            tenant_id=request.tenant_id,
            learning_type=request.learning_type,
            entries=request.entries,
            source=request.source,
        )
    except Exception as e:
        logger.error("Hermes learning sync error: %s", e)
        raise HTTPException(status_code=503, detail="Learning store unavailable") from e

    return HermesLearningSyncResponse(status="success", entries_processed=processed)


@router.get("/health")
async def hermes_integration_health() -> Dict[str, Any]:
    return {
        "status": "healthy" if current_bridge() is not None else "degraded",
        "service": "hermes-integration",
        "timestamp": _now(),
        "capabilities": [
            "agent_coordination",
            "workflow_orchestration",
            "learning_sync",
            "multi_channel_support",
        ],
    }


@router.get("/metrics", dependencies=protected)
async def hermes_integration_metrics(
    hermes_bridge: HermesIntegrationBridge = Depends(get_hermes_bridge),
) -> Dict[str, Any]:
    return {**hermes_bridge.snapshot_metrics(), "timestamp": _now()}


@router.post("/webhook/{integration_type}", dependencies=protected)
async def handle_integration_webhook(
    integration_type: str,
    payload: Dict[str, Any],
    tenant_id: str = Query(..., min_length=1),
    hermes_bridge: HermesIntegrationBridge = Depends(get_hermes_bridge),
) -> Dict[str, Any]:
    """Route an external integration's webhook event to the agents."""
    received_at = datetime.now(timezone.utc)
    result = await hermes_bridge.coordinate_request({
        'tenant_id': tenant_id,
        'message': f'Webhook event from {integration_type}: {payload.get("type", "unknown")}',
        'channel': 'webhook',
        'conversation_id': f'webhook_{integration_type}_{int(received_at.timestamp())}',
        'user_context': {'integration_type': integration_type},
        'intent_analysis': {'type': 'external_integration'},
        'metadata': {
            'webhook_source': integration_type,
            'webhook_payload': payload,
            'webhook_timestamp': received_at.isoformat(),
        },
    })
    return {'status': 'processed', 'coordination_result': result, 'timestamp': _now()}
