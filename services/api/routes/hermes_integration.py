"""
Hermes Integration API Routes
FastAPI endpoints for Hermes Agent coordination with SpiderNetOS
"""

import logging
from datetime import datetime
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, BackgroundTasks, Depends, HTTPException
from pydantic import BaseModel, Field

from services.shared.hermes_bridge import HermesIntegrationBridge, get_hermes_bridge

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api/hermes", tags=["hermes-integration"])

# Request/Response Models

class HermesCoordinationRequest(BaseModel):
    """Request from Hermes Agent for SpiderNetOS coordination."""
    message: str = Field(..., description="User message to process")
    channel: str = Field(..., description="Communication channel (voice, text, email, etc.)")
    conversation_id: str = Field(..., description="Unique conversation identifier")
    user_context: Optional[Dict[str, Any]] = Field(None, description="User context and preferences")
    intent_analysis: Optional[Dict[str, Any]] = Field(None, description="Hermes intent analysis")
    metadata: Optional[Dict[str, Any]] = Field(None, description="Additional metadata")
    user_satisfaction: Optional[float] = Field(0.5, description="User satisfaction score", ge=0.0, le=1.0)

class HermesCoordinationResponse(BaseModel):
    """Response to Hermes coordination request."""
    status: str = Field(..., description="Response status")
    response: str = Field(..., description="Coordinated response text")
    agents_used: List[str] = Field(default_factory=list, description="Agents that participated")
    workflow_triggers: List[str] = Field(default_factory=list, description="Workflows triggered")
    learning_signals: Dict[str, Any] = Field(default_factory=dict, description="Learning data for RL")
    execution_time_ms: float = Field(..., description="Execution time in milliseconds")
    timestamp: str = Field(default_factory=lambda: datetime.utcnow().isoformat())

class HermesLearningSyncRequest(BaseModel):
    """Learning data sync from Hermes Agent."""
    source: str = Field(..., description="Data source (hermes)")
    learning_type: str = Field(..., description="Type of learning data")
    entries: List[Dict[str, Any]] = Field(..., description="Learning data entries")
    sync_timestamp: str = Field(..., description="Sync timestamp")

class HermesLearningSyncResponse(BaseModel):
    """Response to learning data sync."""
    status: str = Field(..., description="Sync status")
    entries_processed: int = Field(..., description="Number of entries processed")
    timestamp: str = Field(default_factory=lambda: datetime.utcnow().isoformat())

# API Endpoints

@router.post("/coordinate", response_model=HermesCoordinationResponse)
async def coordinate_with_spidernet(
    request: HermesCoordinationRequest,
    background_tasks: BackgroundTasks,
    hermes_bridge: HermesIntegrationBridge = Depends(get_hermes_bridge)
) -> HermesCoordinationResponse:
    """
    Main coordination endpoint for Hermes Agent.

    Receives requests from Hermes and coordinates with appropriate SpiderNetOS agents,
    then returns the coordinated response with learning signals.
    """

    start_time = datetime.utcnow()

    try:
        # Convert request to dict for processing
        request_dict = request.dict()

        # Add coordination request to background tasks for learning
        background_tasks.add_task(
            log_coordination_request,
            request_dict
        )

        # Process through Hermes bridge
        coordination_result = await hermes_bridge.coordinate_request(request_dict)

        execution_time = (datetime.utcnow() - start_time).total_seconds() * 1000

        # Add execution time to result
        coordination_result['execution_time_ms'] = execution_time

        # Validate response structure
        if 'status' not in coordination_result:
            coordination_result['status'] = 'unknown'

        if 'response' not in coordination_result:
            coordination_result['response'] = 'Processing completed.'

        return HermesCoordinationResponse(**coordination_result)

    except Exception as e:
        logger.error(f"Hermes coordination API error: {e}")
        execution_time = (datetime.utcnow() - start_time).total_seconds() * 1000

        return HermesCoordinationResponse(
            status="error",
            response="An error occurred during coordination. Please try again.",
            agents_used=[],
            workflow_triggers=[],
            learning_signals={},
            execution_time_ms=execution_time
        )

@router.post("/learning/sync", response_model=HermesLearningSyncResponse)
async def sync_hermes_learning(
    request: HermesLearningSyncRequest,
    hermes_bridge: HermesIntegrationBridge = Depends(get_hermes_bridge)
) -> HermesLearningSyncResponse:
    """
    Sync learning data from Hermes Agent for RL training.

    Receives communication pattern learning data from Hermes and stores it
    for SpiderNetOS RL model training.
    """

    try:
        entries_processed = len(request.entries)

        # Process learning data through bridge
        await hermes_bridge._sync_learning_data_manual(request.dict())

        logger.info(f"Successfully synced {entries_processed} learning entries from Hermes")

        return HermesLearningSyncResponse(
            status="success",
            entries_processed=entries_processed
        )

    except Exception as e:
        logger.error(f"Hermes learning sync error: {e}")
        return HermesLearningSyncResponse(
            status="error",
            entries_processed=0
        )

@router.get("/health")
async def hermes_integration_health() -> Dict[str, Any]:
    """Health check for Hermes integration."""

    return {
        "status": "healthy",
        "service": "hermes-integration",
        "timestamp": datetime.utcnow().isoformat(),
        "capabilities": [
            "agent_coordination",
            "workflow_orchestration",
            "learning_sync",
            "multi_channel_support"
        ]
    }

@router.get("/metrics")
async def hermes_integration_metrics() -> Dict[str, Any]:
    """Metrics for Hermes integration monitoring."""

    # Placeholder metrics (would integrate with actual metrics collection)
    return {
        "coordination_requests_total": 0,
        "learning_entries_synced_total": 0,
        "active_conversations": 0,
        "average_response_time_ms": 0.0,
        "coordination_success_rate": 1.0,
        "timestamp": datetime.utcnow().isoformat()
    }

@router.post("/webhook/{integration_type}")
async def handle_integration_webhook(
    integration_type: str,
    payload: Dict[str, Any],
    hermes_bridge: HermesIntegrationBridge = Depends(get_hermes_bridge)
) -> Dict[str, Any]:
    """
    Handle webhooks from external integrations.

    Routes webhook events through Hermes coordination system.
    """

    try:
        # Create coordination request from webhook
        coordination_request = {
            'message': f'Webhook event from {integration_type}: {payload.get("type", "unknown")}',
            'channel': 'webhook',
            'conversation_id': f'webhook_{integration_type}_{int(datetime.utcnow().timestamp())}',
            'user_context': {'integration_type': integration_type},
            'intent_analysis': {'type': 'external_integration'},
            'metadata': {
                'webhook_source': integration_type,
                'webhook_payload': payload,
                'webhook_timestamp': datetime.utcnow().isoformat()
            }
        }

        # Process through coordination
        result = await hermes_bridge.coordinate_request(coordination_request)

        return {
            'status': 'processed',
            'coordination_result': result,
            'timestamp': datetime.utcnow().isoformat()
        }

    except Exception as e:
        logger.error(f"Webhook processing error: {e}")
        return {
            'status': 'error',
            'error': str(e),
            'timestamp': datetime.utcnow().isoformat()
        }

# Helper Functions

async def log_coordination_request(request_dict: Dict[str, Any]):
    """Log coordination requests for analytics."""
    try:
        # Log to database or monitoring system
        logger.info(f"Hermes coordination request: {request_dict.get('conversation_id', 'unknown')} "
                   f"from {request_dict.get('channel', 'unknown')} channel")
    except Exception as e:
        logger.error(f"Failed to log coordination request: {e}")

# Error Handlers

@router.exception_handler(HTTPException)
async def http_exception_handler(request, exc):
    return {
        "status": "error",
        "error": exc.detail,
        "error_code": exc.status_code,
        "timestamp": datetime.utcnow().isoformat()
    }

@router.exception_handler(Exception)
async def general_exception_handler(request, exc):
    logger.error(f"Unhandled exception in Hermes integration: {exc}")
    return {
        "status": "error",
        "error": "Internal server error",
        "error_code": 500,
        "timestamp": datetime.utcnow().isoformat()
    }
