"""
SpiderNet OS — Inference Plane (FastAPI)
Policy-based model routing with cost-aware fallback cascade.
"""
import json
import re
from datetime import datetime
from typing import Any, List, Literal, Optional

import httpx
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field, field_validator, model_validator

from models import InferenceRequest, InferenceResponse, HealthResponse
from policy_router import route_request
from config import (
    DEFAULT_COST_CEILING,
    DEFAULT_OLLAMA_MODEL,
    OLLAMA_URL,
    EMBEDDING_MODEL,
    EMBEDDING_DIM,
    VISION_DEFAULT_MODEL,
)

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
    # Optional caller-supplied intent enum. When provided, the valid-intent
    # clamp uses it instead of the hardcoded INTENT_ENUM.
    intent_enum: Optional[List[str]] = None


class ClassifyResponse(BaseModel):
    intent: str
    entities: dict = Field(default_factory=dict)
    confidence: float = Field(ge=0.0, le=1.0)


INTENT_ENUM = [
    "create_flow", "execute_flow", "query_status", "analyze_data",
    "teach", "monitor", "manage_agent", "chat",
]


def _clamp_intent(intent: str, intent_enum: Optional[List[str]] = None) -> str:
    """Clamp a model-produced intent to the valid enum.

    Uses the caller-supplied enum when provided, else the hardcoded
    INTENT_ENUM. Invalid intents fall back to "chat" when it is a valid
    member, otherwise to the first enum entry.
    """
    valid = list(intent_enum) if intent_enum else INTENT_ENUM
    if intent in valid:
        return intent
    return "chat" if "chat" in valid else valid[0]


@app.post("/v1/classify", response_model=ClassifyResponse)
async def classify(request: ClassifyRequest):
    """Structured intent classification for AtlasIntentCompiler."""
    valid_intents = request.intent_enum or INTENT_ENUM
    system = request.system_prompt or (
        "Classify the user message into one intent and extract entities. "
        "Respond with JSON only: {\"intent\": \"...\", \"entities\": {}, \"confidence\": 0.0-1.0}. "
        f"Valid intents: {', '.join(valid_intents)}."
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
        intent = _clamp_intent(str(parsed.get("intent", "chat")), request.intent_enum)
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


# ─── Document Extraction (/v1/extract-document) ──────────────────────────────


def _extract_json_deep(text: str) -> Optional[dict]:
    """Brace-counting JSON extractor for arbitrarily nested objects.

    The flat regex in _extract_json_object cannot match nested structures
    (e.g. a line_items array of objects). This walks the text, tracking
    string literals and escape sequences, and returns the first top-level
    {...} span that parses as a JSON object. Returns None when no object
    can be extracted.
    """
    if not text:
        return None

    cleaned = text.strip()
    # Strip markdown code fences if the whole payload is fenced.
    fence = re.match(r"^```(?:json)?\s*(.*?)\s*```$", cleaned, re.DOTALL)
    if fence:
        cleaned = fence.group(1).strip()
    try:
        parsed = json.loads(cleaned)
        if isinstance(parsed, dict):
            return parsed
    except json.JSONDecodeError:
        pass

    start = 0
    while True:
        start = text.find("{", start)
        if start == -1:
            return None
        depth = 0
        in_string = False
        escape = False
        end = None
        for i in range(start, len(text)):
            ch = text[i]
            if in_string:
                if escape:
                    escape = False
                elif ch == "\\":
                    escape = True
                elif ch == '"':
                    in_string = False
                continue
            if ch == '"':
                in_string = True
            elif ch == "{":
                depth += 1
            elif ch == "}":
                depth -= 1
                if depth == 0:
                    end = i
                    break
        if end is not None:
            candidate = text[start:end + 1]
            try:
                parsed = json.loads(candidate)
                if isinstance(parsed, dict):
                    return parsed
            except json.JSONDecodeError:
                pass
        start += 1


class ExtractedField(BaseModel):
    value: Optional[Any] = None
    confidence: float = 0.0

    @field_validator("confidence", mode="before")
    @classmethod
    def _clamp_confidence(cls, v):
        try:
            v = float(v)
        except (TypeError, ValueError):
            return 0.0
        return max(0.0, min(1.0, v))


class ExtractedLineItem(BaseModel):
    description: Optional[str] = None
    amount: Optional[float] = None
    quantity: Optional[float] = None
    confidence: float = 0.0

    @field_validator("confidence", mode="before")
    @classmethod
    def _clamp_confidence(cls, v):
        try:
            v = float(v)
        except (TypeError, ValueError):
            return 0.0
        return max(0.0, min(1.0, v))


class ExtractDocumentFields(BaseModel):
    merchant: ExtractedField = Field(default_factory=ExtractedField)
    date: ExtractedField = Field(default_factory=ExtractedField)
    total: ExtractedField = Field(default_factory=ExtractedField)
    tax: ExtractedField = Field(default_factory=ExtractedField)
    currency: ExtractedField = Field(default_factory=ExtractedField)
    line_items: List[ExtractedLineItem] = Field(default_factory=list)


class ExtractDocumentRequest(BaseModel):
    doc_kind: Literal["receipt", "bill"] = "receipt"
    content_type: Optional[str] = None
    image_b64: Optional[str] = None
    text: Optional[str] = None
    tenant_id: Optional[int] = None
    tenant_tier: str = "starter"
    cost_ceiling: Optional[float] = None
    model: Optional[str] = None

    @model_validator(mode="after")
    def _exactly_one_input(self):
        has_image = bool(self.image_b64 and self.image_b64.strip())
        has_text = bool(self.text and self.text.strip())
        if has_image == has_text:
            raise ValueError("Provide exactly one of image_b64 or text")
        return self


class ExtractDocumentResponse(BaseModel):
    fields: ExtractDocumentFields
    overall_confidence: float
    method: Literal["vision", "text", "heuristic"]
    model: str
    provider: str
    tokens_used: int = 0
    cost: float = 0.0
    latency_ms: float = 0.0


# Date normalization — multi-format parse to ISO (YYYY-MM-DD).
_DATE_FORMATS = [
    "%Y-%m-%d", "%Y/%m/%d",
    "%d/%m/%Y", "%m/%d/%Y", "%d-%m-%Y", "%m-%d-%Y", "%d.%m.%Y",
    "%d/%m/%y", "%m/%d/%y",
    "%d %b %Y", "%d %B %Y", "%b %d %Y", "%B %d %Y",
    "%b %d, %Y", "%B %d, %Y", "%d %b, %Y", "%d %B, %Y",
]


def _normalize_date(value: Any) -> Optional[str]:
    """Normalize a date of any common receipt format to ISO YYYY-MM-DD."""
    if value is None:
        return None
    s = str(value).strip()
    if not s:
        return None
    s = re.sub(r"(\d)(st|nd|rd|th)\b", r"\1", s)  # 3rd Mar 2026 -> 3 Mar 2026
    s = re.sub(r"\s+", " ", s)
    for fmt in _DATE_FORMATS:
        try:
            dt = datetime.strptime(s, fmt)
        except ValueError:
            continue
        if dt.year < 1000:
            # %Y happily parses 2-digit years ("26" -> year 26); let a later
            # %y format pick it up instead.
            continue
        return dt.date().isoformat()
    m = re.search(r"\d{4}-\d{2}-\d{2}", s)
    return m.group(0) if m else None


def _coerce_amount(value: Any) -> Optional[float]:
    """Coerce '£1,234.50' / 1234.5 / '1234.50' into a float, else None."""
    if value is None:
        return None
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return float(value)
    s = re.sub(r"[^\d.,\-]", "", str(value)).replace(",", "")
    if not s:
        return None
    try:
        return float(s)
    except ValueError:
        return None


# ─── Heuristic (regex) extraction fallback ──────────────────────────────────

_AMOUNT_RE = re.compile(r"(?<![\d.])(\d{1,3}(?:,\d{3})*\.\d{2}|\d+\.\d{2})(?!\d)")
_TOTAL_KEYWORDS_RE = re.compile(
    r"\b(grand\s*total|amount\s*due|balance\s*due|total\s*due|total)\b", re.IGNORECASE)
_SUBTOTAL_RE = re.compile(r"\bsub\s*-?\s*total\b", re.IGNORECASE)
_TAX_RE = re.compile(r"\b(vat|tax|gst)\b", re.IGNORECASE)
_PAYMENT_RE = re.compile(
    r"\b(cash|change|card|visa|mastercard|amex|tender|payment|paid)\b", re.IGNORECASE)
_CURRENCY_SYMBOL_MAP = {"$": "USD", "£": "GBP", "€": "EUR", "¥": "JPY"}
_CURRENCY_CODE_RE = re.compile(r"\b(USD|GBP|EUR|ZAR|JPY|CAD|AUD|NGN|KES|INR|CNY)\b")
_DATE_CANDIDATE_PATTERNS = [
    r"\d{4}[-/]\d{1,2}[-/]\d{1,2}",
    r"\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4}",
    r"\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?,?\s+\d{2,4}",
    r"(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},?\s+\d{2,4}",
]


def _heuristic_extract(text: str) -> dict:
    """Regex-based receipt/bill field extraction (no LLM).

    Total: currency-amount candidates with a Total/Amount-Due proximity
    boost. Date: multi-format parse. Merchant: first alphabetic line.
    All confidences are capped at 0.4.
    """
    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]

    # Merchant: first line with meaningful alphabetic content.
    merchant = None
    for ln in lines:
        if sum(c.isalpha() for c in ln) >= 3 and not _TOTAL_KEYWORDS_RE.search(ln) \
                and not _TAX_RE.search(ln) and not _PAYMENT_RE.search(ln):
            merchant = ln
            break

    # Amount candidates with Total/Amount-Due proximity boost.
    boosted: List[float] = []
    candidates: List[float] = []
    tax_value: Optional[float] = None
    for ln in lines:
        amounts = [float(m.group(1).replace(",", "")) for m in _AMOUNT_RE.finditer(ln)]
        candidates.extend(amounts)
        if amounts and _TOTAL_KEYWORDS_RE.search(ln) and not _SUBTOTAL_RE.search(ln):
            boosted.extend(amounts)
        if tax_value is None and amounts and _TAX_RE.search(ln):
            tax_value = amounts[-1]

    if boosted:
        total_value, total_conf = max(boosted), 0.4
    elif candidates:
        total_value, total_conf = max(candidates), 0.25
    else:
        total_value, total_conf = None, 0.0

    # Date: first candidate that parses in any supported format.
    date_value = None
    for pattern in _DATE_CANDIDATE_PATTERNS:
        for m in re.finditer(pattern, text, re.IGNORECASE):
            date_value = _normalize_date(m.group(0))
            if date_value:
                break
        if date_value:
            break

    # Currency: symbol first, ISO code second.
    currency_value = None
    for symbol, code in _CURRENCY_SYMBOL_MAP.items():
        if symbol in text:
            currency_value = code
            break
    if currency_value is None:
        code_match = _CURRENCY_CODE_RE.search(text)
        if code_match:
            currency_value = code_match.group(1)

    # Line items: description + trailing amount, excluding totals/tax/payment.
    line_items = []
    for ln in lines:
        if _TOTAL_KEYWORDS_RE.search(ln) or _SUBTOTAL_RE.search(ln) \
                or _TAX_RE.search(ln) or _PAYMENT_RE.search(ln):
            continue
        m = _AMOUNT_RE.search(ln)
        if not m:
            continue
        description = ln[:m.start()].strip(" .-:\t*·|")
        if sum(c.isalpha() for c in description) < 2:
            continue
        line_items.append({
            "description": description,
            "amount": float(m.group(1).replace(",", "")),
            "quantity": None,
            "confidence": 0.2,
        })
        if len(line_items) >= 25:
            break

    return {
        "merchant": {"value": merchant, "confidence": 0.3 if merchant else 0.0},
        "date": {"value": date_value, "confidence": 0.35 if date_value else 0.0},
        "total": {"value": total_value, "confidence": total_conf},
        "tax": {"value": tax_value, "confidence": 0.3 if tax_value is not None else 0.0},
        "currency": {"value": currency_value, "confidence": 0.35 if currency_value else 0.0},
        "line_items": line_items,
    }


def _overall_confidence(fields: ExtractDocumentFields) -> float:
    confs = [
        fields.merchant.confidence, fields.date.confidence,
        fields.total.confidence, fields.tax.confidence,
        fields.currency.confidence,
    ]
    confs.extend(item.confidence for item in fields.line_items)
    return round(sum(confs) / len(confs), 3) if confs else 0.0


def _fields_from_dict(parsed: dict) -> ExtractDocumentFields:
    """Validate/clamp a parsed fields dict (LLM or heuristic) via pydantic."""
    def scalar(name: str) -> ExtractedField:
        raw = parsed.get(name)
        if isinstance(raw, dict):
            value, conf = raw.get("value"), raw.get("confidence", 0.5)
        elif raw is None:
            value, conf = None, 0.0
        else:
            value, conf = raw, 0.5
        return ExtractedField(value=value, confidence=conf)

    merchant = scalar("merchant")
    if merchant.value is not None:
        merchant.value = str(merchant.value).strip() or None

    date = scalar("date")
    date.value = _normalize_date(date.value)

    total = scalar("total")
    total.value = _coerce_amount(total.value)

    tax = scalar("tax")
    tax.value = _coerce_amount(tax.value)

    currency = scalar("currency")
    if currency.value is not None:
        code = str(currency.value).strip().upper()
        currency.value = code if re.fullmatch(r"[A-Z]{3}", code) else \
            _CURRENCY_SYMBOL_MAP.get(code)

    for f in (merchant, date, total, tax, currency):
        if f.value is None:
            f.confidence = 0.0

    line_items = []
    raw_items = parsed.get("line_items")
    if isinstance(raw_items, list):
        for raw in raw_items[:50]:
            if not isinstance(raw, dict):
                continue
            line_items.append(ExtractedLineItem(
                description=str(raw.get("description")) if raw.get("description") is not None else None,
                amount=_coerce_amount(raw.get("amount")),
                quantity=_coerce_amount(raw.get("quantity")),
                confidence=raw.get("confidence", 0.5),
            ))

    return ExtractDocumentFields(
        merchant=merchant, date=date, total=total, tax=tax,
        currency=currency, line_items=line_items,
    )


def _heuristic_response(text: str) -> ExtractDocumentResponse:
    fields = _fields_from_dict(_heuristic_extract(text))
    return ExtractDocumentResponse(
        fields=fields,
        overall_confidence=_overall_confidence(fields),
        method="heuristic",
        model="heuristic",
        provider="local",
        tokens_used=0,
        cost=0.0,
        latency_ms=0.0,
    )


def _strip_data_url(b64: str) -> str:
    """Accept either raw base64 or a data: URL; return raw base64."""
    b64 = b64.strip()
    if b64.startswith("data:"):
        _, _, rest = b64.partition(",")
        return rest or b64
    return b64


def _build_extraction_prompt(doc_kind: str, text: Optional[str]) -> tuple:
    system = (
        f"You are a strict {doc_kind} data extraction engine. "
        "Respond with a single JSON object only — no prose, no markdown fences. Schema: "
        '{"merchant": {"value": string|null, "confidence": 0.0-1.0}, '
        '"date": {"value": "YYYY-MM-DD"|null, "confidence": 0.0-1.0}, '
        '"total": {"value": number|null, "confidence": 0.0-1.0}, '
        '"tax": {"value": number|null, "confidence": 0.0-1.0}, '
        '"currency": {"value": "ISO-4217 code"|null, "confidence": 0.0-1.0}, '
        '"line_items": [{"description": string, "amount": number, '
        '"quantity": number|null, "confidence": 0.0-1.0}]}. '
        "Every field carries its own confidence between 0 and 1. "
        "Use null for unreadable or absent fields. Dates must be ISO YYYY-MM-DD."
    )
    if text:
        prompt = f"Extract the fields from this {doc_kind} text:\n\n{text}"
    else:
        prompt = f"Extract the fields from the attached {doc_kind} image."
    return system, prompt


@app.post("/v1/extract-document", response_model=ExtractDocumentResponse)
async def extract_document(request: ExtractDocumentRequest):
    """Vision/text document extraction with policy routing and heuristic fallback."""
    has_image = bool(request.image_b64 and request.image_b64.strip())
    has_text = bool(request.text and request.text.strip())

    ceiling = request.cost_ceiling if request.cost_ceiling is not None else DEFAULT_COST_CEILING
    if ceiling <= 0:
        # Budget exhausted: never call an LLM. Heuristics-or-422.
        if has_text:
            return _heuristic_response(request.text)
        raise HTTPException(
            status_code=422,
            detail="cost_ceiling exhausted and no text provided for heuristic extraction",
        )

    system, prompt = _build_extraction_prompt(request.doc_kind, request.text if has_text else None)
    images = [_strip_data_url(request.image_b64)] if has_image else None
    model = request.model or (VISION_DEFAULT_MODEL if has_image else None)

    inference_request = InferenceRequest(
        prompt=prompt,
        system_prompt=system,
        model=model,
        tenant_id=request.tenant_id,
        tenant_tier=request.tenant_tier,
        cost_ceiling=ceiling,
        temperature=0.0,
        max_tokens=1024,
        images=images,
    )

    try:
        # Cost accounting flows through route_request -> record_usage.
        result = await route_request(
            inference_request,
            require_capability="vision" if has_image else None,
        )
    except Exception as exc:
        if has_text:
            # All models failed but we have text: degrade to heuristics, 200.
            return _heuristic_response(request.text)
        raise HTTPException(status_code=502, detail=f"extract_failed: {exc}")

    parsed = _extract_json_deep(result.text)
    if parsed is None and has_text:
        return _heuristic_response(request.text)
    fields = _fields_from_dict(parsed or {})

    return ExtractDocumentResponse(
        fields=fields,
        overall_confidence=_overall_confidence(fields),
        method="vision" if has_image else "text",
        model=result.model,
        provider=result.provider,
        tokens_used=result.tokens_used,
        cost=result.cost,
        latency_ms=result.latency_ms,
    )


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
