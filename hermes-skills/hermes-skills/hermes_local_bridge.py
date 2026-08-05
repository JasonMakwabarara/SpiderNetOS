"""
Hermes Local Bridge for Windows/Docker Deployment
Uses Ollama models (gemma4:31b, qwen3.6) instead of cloud API
"""
import asyncio
import json
import logging
from typing import Dict, Any, List, Optional
from datetime import datetime
import requests
import redis
from fastapi import FastAPI, HTTPException, BackgroundTasks
from pydantic import BaseModel, Field
import os
import uvicorn

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(title="Hermes Local Bridge", version="1.0.0")

# Configuration
OLLAMA_URL = os.getenv("OLLAMA_URL", "http://127.0.0.1:11434")
SPIDERNET_API_URL = os.getenv("SPIDERNET_API_URL", "http://127.0.0.1:8000")
REDIS_URL = os.getenv("REDIS_URL", "redis://127.0.0.1:6379/2")
PRIMARY_MODEL = "qwen3.6:latest"
FALLBACK_MODEL = "gemma4:31b"

# Initialize Redis
try:
    redis_client = redis.Redis.from_url(REDIS_URL, decode_responses=True)
    redis_client.ping()
    logger.info("Redis connected")
except Exception as e:
    logger.warning(f"Redis not available: {e}")
    redis_client = None


class CoordinateRequest(BaseModel):
    """Request for agent coordination."""
    message: str = Field(..., description="User message")
    channel: str = Field(default="api", description="Communication channel")
    conversation_id: Optional[str] = Field(default=None, description="Conversation ID")
    user_context: Optional[Dict[str, Any]] = Field(default_factory=dict)
    intent_analysis: Optional[Dict[str, Any]] = Field(default_factory=dict)


class CoordinateResponse(BaseModel):
    """Response from coordination."""
    status: str = Field(..., description="Status")
    response: str = Field(..., description="Response text")
    model_used: str = Field(..., description="Model used for processing")
    agents_used: List[str] = Field(default_factory=list)
    execution_time_ms: float = Field(..., description="Execution time")
    timestamp: str = Field(default_factory=lambda: datetime.utcnow().isoformat())


def query_ollama(prompt: str, model: str = PRIMARY_MODEL, timeout: int = 120) -> str:
    """Query Ollama with fallback to secondary model."""
    try:
        response = requests.post(
            f"{OLLAMA_URL}/api/generate",
            json={
                "model": model,
                "prompt": prompt,
                "stream": False,
                "options": {
                    "temperature": 0.7,
                    "num_predict": 500
                }
            },
            timeout=timeout
        )
        response.raise_for_status()
        data = response.json()
        return data.get("response", data.get("thinking", ""))
    except Exception as e:
        logger.error(f"Ollama query failed for {model}: {e}")
        if model != FALLBACK_MODEL:
            logger.info(f"Trying fallback model: {FALLBACK_MODEL}")
            return query_ollama(prompt, FALLBACK_MODEL, timeout)
        raise


def analyze_intent(message: str) -> Dict[str, Any]:
    """Analyze user intent using Ollama."""
    prompt = f"""Analyze this message and classify intent for a multi-agent AI platform:

Message: "{message}"

Respond in JSON format:
{{
    "intent_type": "simple_query|complex_workflow|multi_agent_coordination|external_integration",
    "confidence": 0.0-1.0,
    "primary_agent": "atlas|nexus|prism|sentinel|forge|hannah",
    "complexity": "low|medium|high",
    "requires_workflow": true|false,
    "explanation": "brief explanation"
}}

JSON response:"""

    try:
        response = query_ollama(prompt, PRIMARY_MODEL, timeout=120)
        # Extract JSON from response
        json_start = response.find('{')
        json_end = response.rfind('}') + 1
        if json_start >= 0 and json_end > json_start:
            return json.loads(response[json_start:json_end])
    except Exception as e:
        logger.error(f"Intent analysis failed: {e}")

    # Default fallback
    return {
        "intent_type": "simple_query",
        "confidence": 0.5,
        "primary_agent": "atlas",
        "complexity": "low",
        "requires_workflow": False
    }


def generate_response(message: str, intent: Dict[str, Any], context: Dict) -> str:
    """Generate response using appropriate model."""
    agent_name = intent.get("primary_agent", "atlas")
    complexity = intent.get("complexity", "low")

    # Use primary model for all complexities (qwen3.6 is faster)
    model = PRIMARY_MODEL

    prompt = f"""Respond as a friendly AI assistant to this message: "{message}"

Provide a helpful and natural response."""

    return query_ollama(prompt, model)


@app.get("/health")
async def health_check():
    """Health check endpoint."""
    health = {
        "status": "healthy",
        "service": "hermes-local-bridge",
        "timestamp": datetime.utcnow().isoformat(),
        "models": {
            "primary": PRIMARY_MODEL,
            "fallback": FALLBACK_MODEL
        },
        "integrations": {
            "ollama": False,
            "spidernet": False,
            "redis": False
        }
    }

    # Check Ollama
    try:
        response = requests.get(f"{OLLAMA_URL}/api/tags", timeout=5)
        if response.status_code == 200:
            health["integrations"]["ollama"] = True
    except:
        pass

    # Check SpiderNetOS
    try:
        response = requests.get(f"{SPIDERNET_API_URL}/health", timeout=5)
        if response.status_code == 200:
            health["integrations"]["spidernet"] = True
    except:
        pass

    # Check Redis
    if redis_client:
        try:
            redis_client.ping()
            health["integrations"]["redis"] = True
        except:
            pass

    return health


@app.post("/api/coordinate", response_model=CoordinateResponse)
async def coordinate(request: CoordinateRequest, background_tasks: BackgroundTasks):
    """Coordinate request through SpiderNetOS agents."""
    start_time = datetime.utcnow()

    try:
        # Analyze intent
        intent = analyze_intent(request.message)

        # Generate response
        response_text = generate_response(
            request.message,
            intent,
            request.user_context
        )

        execution_time = (datetime.utcnow() - start_time).total_seconds() * 1000

        # Log to Redis for learning
        if redis_client:
            log_entry = {
                "timestamp": datetime.utcnow().isoformat(),
                "message": request.message,
                "intent": intent,
                "response": response_text,
                "channel": request.channel
            }
            redis_client.lpush("hermes:learning:patterns", json.dumps(log_entry))

        # Forward to SpiderNetOS if workflow required
        if intent.get("requires_workflow"):
            background_tasks.add_task(
                forward_to_spidernet,
                request.message,
                intent,
                request.user_context
            )

        return CoordinateResponse(
            status="success",
            response=response_text,
            model_used=PRIMARY_MODEL if intent.get("complexity") in ["medium", "high"] else FALLBACK_MODEL,
            agents_used=[intent.get("primary_agent", "atlas")],
            execution_time_ms=execution_time
        )

    except Exception as e:
        logger.error(f"Coordination error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


def forward_to_spidernet(message: str, intent: Dict, context: Dict):
    """Forward complex requests to SpiderNetOS API."""
    try:
        payload = {
            "message": message,
            "intent_analysis": intent,
            "user_context": context,
            "channel": "hermes",
            "conversation_id": f"hermes_{datetime.utcnow().timestamp()}"
        }

        response = requests.post(
            f"{SPIDERNET_API_URL}/api/hermes/coordinate",
            json=payload,
            timeout=30
        )
        response.raise_for_status()
        logger.info(f"Forwarded to SpiderNetOS: {response.status_code}")
    except Exception as e:
        logger.error(f"Failed to forward to SpiderNetOS: {e}")


@app.post("/api/learning/sync")
async def learning_sync():
    """Manually trigger learning sync."""
    if not redis_client:
        raise HTTPException(status_code=503, detail="Redis not available")

    patterns = redis_client.lrange("hermes:learning:patterns", 0, 99)
    if not patterns:
        return {"status": "no_data", "entries_processed": 0}

    # Send to SpiderNetOS
    try:
        payload = {
            "source": "hermes",
            "learning_type": "communication_patterns",
            "entries": [json.loads(p) for p in patterns],
            "sync_timestamp": datetime.utcnow().isoformat()
        }

        response = requests.post(
            f"{SPIDERNET_API_URL}/api/hermes/learning-sync",
            json=payload,
            timeout=30
        )
        response.raise_for_status()

        # Clear processed entries
        redis_client.ltrim("hermes:learning:patterns", 100, -1)

        return {
            "status": "synced",
            "entries_processed": len(patterns)
        }
    except Exception as e:
        logger.error(f"Learning sync failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/api/models")
async def list_models():
    """List available Ollama models."""
    try:
        response = requests.get(f"{OLLAMA_URL}/api/tags", timeout=10)
        response.raise_for_status()
        data = response.json()
        return {
            "models": [m.get("name") for m in data.get("models", [])],
            "primary": PRIMARY_MODEL,
            "fallback": FALLBACK_MODEL
        }
    except Exception as e:
        raise HTTPException(status_code=503, detail=f"Cannot reach Ollama: {e}")


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8090)
