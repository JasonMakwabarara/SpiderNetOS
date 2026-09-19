"""
SpiderNet OS — Streaming Inference Endpoint
Server-Sent Events (SSE) for low-latency voice responses.
Streams tokens from Ollama with sentence boundary detection for chunked TTS.
"""

import json
import re
from typing import AsyncGenerator, Optional

import httpx
from config import OLLAMA_URL
from fastapi import HTTPException
from fastapi.responses import StreamingResponse
from pydantic import BaseModel


class StreamingRequest(BaseModel):
    """Request for streaming text generation."""
    model: str = "qwen3"  # Fast model for voice
    messages: list[dict]  # OpenAI-style chat messages
    temperature: float = 0.4
    max_tokens: int = 256
    voice_mode: bool = True  # Enable sentence boundary detection


class StreamChunk(BaseModel):
    """Single chunk of streaming response."""
    type: str  # "token", "sentence", "done", "error"
    content: str = ""
    sentence_num: Optional[int] = None
    latency_ms: Optional[int] = None


# Sentence boundary detection for TTS chunking
SENTENCE_ENDINGS = re.compile(r'[.!?]+\s+')


async def stream_generate(
    request: StreamingRequest,
) -> AsyncGenerator[str, None]:
    """
    Stream tokens from Ollama with sentence boundary detection.

    Yields SSE-formatted events:
    - data: {"type": "token", "content": "Hello"}
    - data: {"type": "sentence", "content": "Hello there!", "sentence_num": 1}
    - data: {"type": "done"}
    """
    start_time = __import__('time').time()
    buffer = ""
    sentence_count = 0

    try:
        # Call Ollama generate endpoint with streaming
        async with httpx.AsyncClient(timeout=30.0) as client, client.stream(
                "POST",
                f"{OLLAMA_URL}/api/generate",
                json={
                    "model": request.model,
                    "prompt": _format_messages(request.messages),
                    "stream": True,
                    "options": {
                        "temperature": request.temperature,
                        "num_predict": request.max_tokens,
                    },
                },
                headers={"Content-Type": "application/json"},
            ) as response:
                response.raise_for_status()

                async for line in response.aiter_lines():
                    if not line:
                        continue

                    try:
                        data = json.loads(line)
                    except json.JSONDecodeError:
                        continue

                    token = data.get("response", "")
                    done = data.get("done", False)

                    if token:
                        latency_ms = int((__import__('time').time() - start_time) * 1000)

                        # Yield token for UI streaming
                        yield f"data: {json.dumps({'type': 'token', 'content': token, 'latency_ms': latency_ms})}\n\n"

                        # Buffer for sentence detection
                        if request.voice_mode:
                            buffer += token

                            # Check for sentence boundary
                            if SENTENCE_ENDINGS.search(buffer):
                                sentences = SENTENCE_ENDINGS.split(buffer)
                                # Keep last fragment if not a complete sentence
                                complete = sentences[:-1] if buffer[-1] not in '.!?' else sentences
                                buffer = sentences[-1] if buffer[-1] not in '.!?' else ""

                                for sent in complete:
                                    if sent.strip():
                                        sentence_count += 1
                                        yield f"data: {json.dumps({'type': 'sentence', 'content': sent.strip(), 'sentence_num': sentence_count})}\n\n"

                    if done:
                        # Flush remaining buffer as final sentence
                        if request.voice_mode and buffer.strip():
                            sentence_count += 1
                            yield f"data: {json.dumps({'type': 'sentence', 'content': buffer.strip(), 'sentence_num': sentence_count})}\n\n"

                        # Send done event
                        total_latency = int((__import__('time').time() - start_time) * 1000)
                        yield f"data: {json.dumps({'type': 'done', 'total_sentences': sentence_count, 'total_latency_ms': total_latency})}\n\n"
                        break

    except httpx.HTTPError as e:
        yield f"data: {json.dumps({'type': 'error', 'content': f'Inference failed: {e}'})}\n\n"
    except Exception as e:
        yield f"data: {json.dumps({'type': 'error', 'content': str(e)})}\n\n"


def _format_messages(messages: list[dict]) -> str:
    """Format OpenAI-style messages into Ollama prompt format."""
    prompt_parts = []
    for msg in messages:
        role = msg.get("role", "user")
        content = msg.get("content", "")

        if role == "system":
            prompt_parts.append(f"System: {content}")
        elif role == "assistant":
            prompt_parts.append(f"Assistant: {content}")
        else:
            prompt_parts.append(f"User: {content}")

    prompt_parts.append("Assistant:")
    return "\n\n".join(prompt_parts)


async def generate_streaming_response(request: StreamingRequest) -> StreamingResponse:
    """
    Create a StreamingResponse for SSE output.

    Usage:
        @app.post("/generate/stream")
        async def stream_endpoint(request: StreamingRequest):
            return generate_streaming_response(request)
    """
    return StreamingResponse(
        stream_generate(request),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",  # Disable nginx buffering
        },
    )


# ─── Voice-Optimized Streaming ─────────────────────────────────────────────


async def stream_voice_response(
    text_prompt: str,
    system_prompt: Optional[str] = None,
    model: str = "qwen3",
) -> AsyncGenerator[bytes, None]:
    """
    Stream audio chunks directly for voice applications.

    This yields audio bytes for each completed sentence, suitable for
    immediate playback via WebSocket or HTTP chunked transfer.

    Yields: Audio bytes (MP3 or WAV) for each sentence
    """
    from speech import get_speech_service

    speech_service = get_speech_service()

    messages = []
    if system_prompt:
        messages.append({"role": "system", "content": system_prompt})
    messages.append({"role": "user", "content": text_prompt})

    request = StreamingRequest(
        model=model,
        messages=messages,
        voice_mode=True,
    )

    async for event_str in stream_generate(request):
        if not event_str.startswith("data: "):
            continue

        try:
            event = json.loads(event_str[6:])  # Remove "data: " prefix
        except json.JSONDecodeError:
            continue

        if event.get("type") == "sentence":
            sentence = event.get("content", "")

            # Synthesize this sentence to audio
            tts_result = await speech_service.synthesize(
                text=sentence,
                provider="piper",  # Use local Piper for speed
                speed=1.0,
            )

            if tts_result.get("audio_base64"):
                import base64
                audio_bytes = base64.b64decode(tts_result["audio_base64"])
                yield audio_bytes

        elif event.get("type") == "done":
            break
        elif event.get("type") == "error":
            raise HTTPException(status_code=500, detail=event.get("content", "Unknown error"))
