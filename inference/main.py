"""
SpiderNet OS — Inference Plane (FastAPI)
Policy-based model routing with cost-aware fallback cascade.
"""
import json
import re
from typing import List, Optional

import httpx
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

from models import InferenceRequest, InferenceResponse, HealthResponse
from policy_router import route_request
from config import DEFAULT_COST_CEILING, DEFAULT_OLLAMA_MODEL, OLLAMA_URL, EMBEDDING_MODEL, EMBEDDING_DIM

app = FastAPI(
    title="SpiderNet OS — Inference Plane",
    version="3.2.0",
    description="Policy-based multi-provider model routing with cost governance",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# ─── Embedding Models ────────────────────────────────────────

class EmbedRequest(BaseModel):
    text: str
    model: Optional[str] = None


class EmbedResponse(BaseModel):
    embedding: List[float]
    model: str
    dimensions: int


# ─── Scaling Models ──────────────────────────────────────────

class ScalingRecommendation(BaseModel):
    action: str
    reason: str
    current_load: float


# ─── Endpoints ───────────────────────────────────────────────

@app.get("/health", response_model=HealthResponse)
async def health():
    return HealthResponse(status="ok", plane="inference", version="3.2.0")


@app.post("/generate", response_model=InferenceResponse)
async def generate(request: InferenceRequest):
    try:
        return await route_request(request)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


class ClassifyRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=8000)
    schema: Optional[dict] = None
    system_prompt: Optional[str] = None
    model: Optional[str] = None


class ClassifyResponse(BaseModel):
    intent: str
    entities: dict = Field(default_factory=dict)
    confidence: float = Field(ge=0.0, le=1.0)


INTENT_ENUM = [
    "create_flow", "execute_flow", "query_status", "analyze_data",
    "teach", "monitor", "manage_agent", "chat",
]


@app.post("/v1/classify", response_model=ClassifyResponse)
async def classify(request: ClassifyRequest):
    """Structured intent classification for AtlasIntentCompiler."""
    system = request.system_prompt or (
        "Classify the user message into one intent and extract entities. "
        "Respond with JSON only: {\"intent\": \"...\", \"entities\": {}, \"confidence\": 0.0-1.0}. "
        f"Valid intents: {', '.join(INTENT_ENUM)}."
    )
    model = request.model or DEFAULT_OLLAMA_MODEL
    try:
        result = await route_request(InferenceRequest(
            prompt=request.message,
            system_prompt=system,
            model=model,
            temperature=0.1,
            max_tokens=512,
            cost_ceiling=DEFAULT_COST_CEILING,
        ))
        parsed = _extract_json_object(result.text)
        intent = str(parsed.get("intent", "chat"))
        if intent not in INTENT_ENUM:
            intent = "chat"
        confidence = float(parsed.get("confidence", 0.6))
        confidence = max(0.0, min(1.0, confidence))
        entities = parsed.get("entities", {})
        if not isinstance(entities, dict):
            entities = {}
        return ClassifyResponse(intent=intent, entities=entities, confidence=confidence)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"classify_failed: {e}")


def _extract_json_object(text: str) -> dict:
    text = text.strip()
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        pass
    match = re.search(r"\{[^{}]*\}", text, re.DOTALL)
    if match:
        try:
            return json.loads(match.group(0))
        except json.JSONDecodeError:
            pass
    return {"intent": "chat", "entities": {}, "confidence": 0.5}


@app.post("/embed", response_model=EmbedResponse)
async def embed(request: EmbedRequest):
    """Generate embeddings via Ollama's embedding endpoint."""
    model = request.model or EMBEDDING_MODEL
    
    try:
        async with httpx.AsyncClient(timeout=30.0) as client:
            resp = await client.post(
                f"{OLLAMA_URL}/api/embed",
                json={"model": model, "input": request.text},
            )
            resp.raise_for_status()
            data = resp.json()
            
            embeddings = data.get("embeddings", [[]])
            embedding = embeddings[0] if embeddings else []
            
            # Truncate or pad to configured dimension
            if len(embedding) > EMBEDDING_DIM:
                embedding = embedding[:EMBEDDING_DIM]
            elif len(embedding) < EMBEDDING_DIM:
                embedding = embedding + [0.0] * (EMBEDDING_DIM - len(embedding))
            
            return EmbedResponse(
                embedding=embedding,
                model=model,
                dimensions=len(embedding),
            )
    except httpx.HTTPError as e:
        raise HTTPException(status_code=502, detail=f"Ollama embedding failed: {e}")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Embedding error: {e}")


@app.get("/usage")
async def usage():
    from cost import get_usage_summary
    return await get_usage_summary()


@app.get("/scaling", response_model=ScalingRecommendation)
async def scaling():
    """Check scaling recommendation based on current metrics."""
    from scaling import check_scaling_recommendation
    rec = check_scaling_recommendation()
    return ScalingRecommendation(
        action=rec.get("action", "none"),
        reason=rec.get("reason", "No scaling needed"),
        current_load=rec.get("current_load", 0.0),
    )


@app.get("/metrics")
async def metrics():
    """Get model success rates and performance metrics."""
    from metrics import get_success_rates
    return await get_success_rates()


# ─── Speech-to-Text & Text-to-Speech ───────────────────────────────────────

from speech import (
    SpeechService, STTRequest, STTResponse, TTSRequest, TTSResponse,
    get_speech_service
)


@app.post("/stt", response_model=STTResponse)
async def stt(request: STTRequest):
    """Speech-to-Text endpoint supporting Whisper, Deepgram, and Twilio."""
    service = get_speech_service()
    return await service.transcribe(request)


@app.post("/tts", response_model=TTSResponse)
async def tts(request: TTSRequest):
    """Text-to-Speech endpoint supporting Piper (local), ElevenLabs (cloud), and Twilio."""
    service = get_speech_service()
    return await service.synthesize(request)


# ─── Streaming Endpoints ─────────────────────────────────────────────────────

from streaming import StreamingRequest, generate_streaming_response, stream_voice_response


@app.post("/generate/stream")
async def generate_stream(request: StreamingRequest):
    """
    Streaming text generation with SSE (Server-Sent Events).
    Optimized for voice: sentence boundaries trigger chunked TTS.
    """
    return generate_streaming_response(request)


# ─── Voice Agent endpoint (Phase B) ─────────────────────────────────────────

import sys, os
# Ensure intelligence package is importable when running from inference/
_INTELLIGENCE_PATH = os.path.join(os.path.dirname(__file__), "..", "intelligence")
if _INTELLIGENCE_PATH not in sys.path:
    sys.path.insert(0, _INTELLIGENCE_PATH)

from pydantic import BaseModel as _BaseModel
from typing import Dict as _Dict, Any as _Any, List as _List, Optional as _Optional


class VoiceAgentRequest(_BaseModel):
    tenant_id:      str
    agent_id:       str
    call_sid:       str
    caller_input:   str
    caller_number:  str                   = ""
    config:         _Dict[str, _Any]      = {}
    transcript:     _List[_Dict]          = []
    tool_allowlist: _Optional[_List[str]] = None
    approval_policy: str                  = "off"


class VoiceAgentResponse(_BaseModel):
    text:                  str
    actions:               _List[str]
    continue_conversation: bool
    model:                 str
    tokens_used:           int
    latency_ms:            int


@app.post("/voice/agent", response_model=VoiceAgentResponse)
async def voice_agent_turn(request: VoiceAgentRequest):
    """
    Phase B — synchronous voice agent turn.
    Called by VoiceController.processViaVoiceAgent() when voice.agent_mode=on.
    """
    try:
        from agents.voice_agent import VoiceAgent, VoiceContext
        from tools.registry import ToolRegistry

        registry = ToolRegistry.instance()

        agent = VoiceAgent(
            ollama_url=OLLAMA_URL,
            model="qwen3",
            tool_registry=registry,
        )

        ctx = VoiceContext(
            tenant_id=request.tenant_id,
            agent_id=request.agent_id,
            call_sid=request.call_sid,
            caller_number=request.caller_number,
            caller_input=request.caller_input,
            transcript=request.transcript,
            tool_allowlist=request.tool_allowlist,
            approval_policy=request.approval_policy,
            config=request.config,
        )

        result = await agent.run(ctx)
        return VoiceAgentResponse(**result)

    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))


# ─── Voice Streaming WebSocket (Phase C) ────────────────────────────────────

@app.websocket("/voice/stream")
async def voice_stream_ws(websocket):
    """
    Twilio Media Streams bidirectional WebSocket endpoint.
    Delegates to voice_pipeline.VoicePipeline.
    Requires voice.streaming feature flag to be on.
    """
    from voice_pipeline import VoicePipeline
    pipeline = VoicePipeline()
    await pipeline.handle_websocket(websocket)


# ─── State Transition Engine — Monte Carlo Simulator (plan §12.5) ──────────


class SteSimulateRequest(BaseModel):
    chain: str
    start_state: str
    matrix: dict
    steps: Optional[int] = 10
    runs: Optional[int] = 1000
    damping: Optional[float] = 0.85
    terminal_states: Optional[List[str]] = None
    seed: Optional[int] = None
    tenant_id: Optional[str] = None


@app.post("/ste/simulate")
async def ste_simulate(req: SteSimulateRequest):
    """
    Monte Carlo walk over a STE transition matrix. The matrix is supplied by
    the caller (the Laravel StateEngineController) to avoid a second DB round
    trip; this keeps the 300ms p95 budget intact.
    """
    try:
        # Local import so the rest of the app is unaffected if the module
        # can't be loaded for any reason.
        import sys
        import os
        sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
        from intelligence.atlas.mc_simulator import simulate

        result = simulate(
            matrix=req.matrix,
            start_state=req.start_state,
            chain=req.chain,
            steps=req.steps or 10,
            runs=req.runs or 1000,
            terminal_states=req.terminal_states,
            damping=req.damping or 0.85,
            seed=req.seed,
        )
        return result
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"ste_simulate_failed: {exc}")
