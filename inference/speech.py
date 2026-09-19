"""
SpiderNet OS — Speech-to-Text & Text-to-Speech Service
Wraps Whisper (local) and Deepgram for STT. TTS goes through tts_providers.py (ElevenLabs primary, Fish Audio,
Intron, Piper local floor, Azure dormant) — the same provider code the voice options page uses.

TTS voice resolution (plan D7 §7): request persona (sent by Laravel's AtlasSpeechService) → request voice +
provider / VOICE_TTS_PROVIDER → VOICE_DEFAULT_PERSONA (a voice_personas.yaml slug) → 503 "no TTS persona
configured". There is no built-in cloud voice. Synthesis falls back persona provider → elevenlabs → piper.
"""

import asyncio
import base64
import io
import os
from pathlib import Path
from typing import Callable, Mapping, Optional, Sequence

import httpx
from config import OLLAMA_URL
from fastapi import HTTPException
from pydantic import BaseModel

import tts_providers
from config import OLLAMA_URL, DEFAULT_COST_CEILING


# ─── Request/Response Models ─────────────────────────────────────────────────


class STTRequest(BaseModel):
    audio_url: Optional[str] = None  # URL to audio file
    audio_base64: Optional[str] = None  # Base64-encoded audio
    provider: Optional[str] = None  # "whisper", "deepgram", "twilio"
    language: str = "en"
    model: str = "base"  # whisper model size: tiny, base, small, medium, large


class STTResponse(BaseModel):
    text: str
    confidence: float
    provider: str
    language: str
    duration_seconds: Optional[float] = None


class TTSRequest(BaseModel):
    text: str
    provider: Optional[str] = None  # "elevenlabs", "fishaudio", "intron", "piper", "azure" (dormant), "twilio"
    voice: Optional[str] = None  # provider voice id; prefer `persona`
    # {slug, provider, voice_id | provider_voice_id, language, accent, gender, style, style_degree, rate, pitch,
    #  output_format} — a voice_personas row as Laravel sends it. Its provider wins over `provider`.
    persona: Optional[dict] = None
    speed: float = 1.0
    format: str = "mp3"  # mp3, wav, pcm


class TTSResponse(BaseModel):
    audio_base64: str
    content_type: str
    provider: str
    duration_estimate: float
    characters: int
    voice: Optional[str] = None  # provider voice id that actually spoke
    persona: Optional[str] = None  # persona slug that actually spoke (None for an env-configured voice)
    fallback_from: Optional[str] = None  # set when a later provider in the chain produced the audio


# ─── Provider Routing ────────────────────────────────────────────────────────


class SpeechService:
    """Unified STT/TTS service with provider fallback."""

    def __init__(
        self,
        environ: Optional[Mapping[str, str]] = None,
        client_factory: Optional[Callable[[], httpx.Client]] = None,
        personas_path: Optional[Path | str] = None,
    ):
        # `environ` is read at call time (os.environ by default), so key rotation needs no restart.
        self.env: Mapping[str, str] = environ if environ is not None else os.environ
        self.client_factory = client_factory or (lambda: httpx.Client(timeout=60.0))
        self.personas_path = personas_path or tts_providers.DEFAULT_PERSONAS_PATH
        self.whisper_url = self.env.get("WHISPER_URL", "http://localhost:9000")
        self.elevenlabs_key = self.env.get("ELEVENLABS_API_KEY", "")
        # No built-in voice: the old hardcoded ElevenLabs "Rachel" default is gone (plan D7 §7).
        self.elevenlabs_voice = self.env.get("ELEVENLABS_VOICE_ID") or None
        self.deepgram_key = self.env.get("DEEPGRAM_API_KEY", "")

    async def transcribe(self, request: STTRequest) -> STTResponse:
        """Speech-to-Text with provider routing."""
        provider = request.provider or os.getenv("VOICE_STT_PROVIDER", "whisper")

        if provider == "whisper":
            return await self._stt_whisper(request)
        elif provider == "deepgram":
            return await self._stt_deepgram(request)
        elif provider == "twilio":
            # Twilio provides transcription via webhook, this is passthrough
            return STTResponse(
                text="",
                confidence=0.0,
                provider="twilio",
                language=request.language,
                duration_seconds=0.0,
            )
        else:
            raise HTTPException(status_code=400, detail=f"Unknown STT provider: {provider}")

    async def synthesize(self, request: TTSRequest) -> TTSResponse:
        """Text-to-Speech routed on persona.provider, else `provider` / VOICE_TTS_PROVIDER, with the
        persona provider → elevenlabs → piper fallback chain."""
        requested = (
            (request.persona or {}).get("provider") or request.provider or self.env.get("VOICE_TTS_PROVIDER") or ""
        ).strip().lower()

        if requested == "twilio":
            # Twilio TTS is handled in TwiML, this is passthrough
            return TTSResponse(
                audio_base64="",
                content_type="text/plain",
                provider="twilio",
                duration_estimate=len(request.text) * 0.08,  # ~150 words/min
                characters=len(request.text),
            )
        if requested and requested not in tts_providers.PROVIDER_ORDER:
            raise HTTPException(status_code=400, detail=f"Unknown TTS provider: {requested}")

        return await self._synthesize(request)

    async def _synthesize(self, request: TTSRequest, chain: Optional[Sequence[str]] = None) -> TTSResponse:
        if not request.text.strip():
            raise HTTPException(status_code=422, detail="text is empty")
        try:
            persona = tts_providers.resolve_persona(
                self.env,
                persona=request.persona,
                provider=chain[0] if chain else request.provider,
                voice=request.voice,
                path=self.personas_path,
            )
        except tts_providers.NoPersonaConfigured as exc:
            raise HTTPException(status_code=503, detail=str(exc))
        if chain and persona["provider"] != chain[0]:
            raise HTTPException(status_code=400, detail=f"persona provider {persona['provider']} is not {chain[0]}")
        if persona["provider"] == "piper" and request.speed != 1.0 and not persona.get("rate"):
            persona["rate"] = request.speed

        try:
            result = await asyncio.to_thread(self._synthesize_sync, request.text, persona, chain)
        except tts_providers.SynthesisFailed as exc:
            raise HTTPException(status_code=502, detail={"message": str(exc), "attempts": exc.attempts})

        speed = request.speed if result.provider == "piper" and request.speed > 0 else 1.0
        return TTSResponse(
            audio_base64=base64.b64encode(result.audio).decode(),
            content_type=result.content_type,
            provider=result.provider,
            duration_estimate=len(request.text.split()) * 0.08 / speed,
            characters=len(request.text),
            voice=result.voice_id,
            persona=result.persona_slug,
            fallback_from=result.fallback_from,
        )

    def _synthesize_sync(
        self, text: str, persona: dict, chain: Optional[Sequence[str]] = None
    ) -> tts_providers.Synthesis:
        """Runs in a worker thread: the providers are synchronous httpx calls (Intron polls)."""
        try:
            default = tts_providers.default_persona(self.env, self.personas_path)
        except tts_providers.NoPersonaConfigured:
            default = None  # a broken VOICE_DEFAULT_PERSONA must not sink a request that named its own persona
        client = self.client_factory()
        try:
            renderers = tts_providers.runtime_renderers(self.env, client)
            return tts_providers.synthesize(text, persona, renderers, env=self.env, default=default, chain=chain)
        finally:
            client.close()

    # ─── STT Implementations ──────────────────────────────────────────────────

    async def _stt_whisper(self, request: STTRequest) -> STTResponse:
        """Local Whisper via Ollama or dedicated Whisper service."""
        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                # If audio_url provided, download it
                audio_data = None
                if request.audio_url:
                    audio_resp = await client.get(request.audio_url, timeout=10.0)
                    audio_resp.raise_for_status()
                    audio_data = audio_resp.content
                elif request.audio_base64:
                    import base64
                    audio_data = base64.b64decode(request.audio_base64)

                if not audio_data:
                    raise HTTPException(status_code=400, detail="No audio provided")

                # Call Whisper via Ollama if available, otherwise assume dedicated endpoint
                try:
                    resp = await client.post(
                        f"{OLLAMA_URL}/api/generate",
                        json={
                            "model": f"whisper-{request.model}",
                            "prompt": "Transcribe this audio:",
                            "audio": request.audio_base64,  # Ollama may support this
                        },
                        timeout=30.0,
                    )
                    resp.raise_for_status()
                    data = resp.json()

                    return STTResponse(
                        text=data.get("response", "").strip(),
                        confidence=0.85,  # Whisper doesn't give confidence directly
                        provider="whisper",
                        language=request.language,
                        duration_seconds=None,
                    )
                except Exception:
                    # Fallback: assume dedicated whisper service
                    files = {"file": ("audio.wav", io.BytesIO(audio_data), "audio/wav")}
                    resp = await client.post(
                        f"{self.whisper_url}/transcribe",
                        files=files,
                        data={"language": request.language, "model": request.model},
                        timeout=30.0,
                    )
                    resp.raise_for_status()
                    data = resp.json()

                    return STTResponse(
                        text=data.get("text", "").strip(),
                        confidence=data.get("confidence", 0.85),
                        provider="whisper",
                        language=data.get("language", request.language),
                        duration_seconds=data.get("duration"),
                    )
        except httpx.HTTPError as e:
            raise HTTPException(status_code=502, detail=f"Whisper STT failed: {e}") from e

    async def _stt_deepgram(self, request: STTRequest) -> STTResponse:
        """Deepgram cloud STT (higher accuracy)."""
        if not self.deepgram_key:
            raise HTTPException(status_code=500, detail="Deepgram API key not configured")

        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                audio_data = None
                if request.audio_url:
                    audio_resp = await client.get(request.audio_url, timeout=10.0)
                    audio_resp.raise_for_status()
                    audio_data = audio_resp.content
                elif request.audio_base64:
                    import base64
                    audio_data = base64.b64decode(request.audio_base64)

                if not audio_data:
                    raise HTTPException(status_code=400, detail="No audio provided")

                resp = await client.post(
                    "https://api.deepgram.com/v1/listen",
                    headers={
                        "Authorization": f"Token {self.deepgram_key}",
                        "Content-Type": "audio/wav",
                    },
                    content=audio_data,
                    params={
                        "model": "nova-2",
                        "language": request.language,
                        "punctuate": "true",
                    },
                )
                resp.raise_for_status()
                data = resp.json()

                result = data.get("results", {}).get("channels", [{}])[0].get("alternatives", [{}])[0]
                words = result.get("words", [])
                confidence = sum(w.get("confidence", 0.99) for w in words) / len(words) if words else 0.99

                return STTResponse(
                    text=result.get("transcript", "").strip(),
                    confidence=round(confidence, 3),
                    provider="deepgram",
                    language=request.language,
                    duration_seconds=data.get("metadata", {}).get("duration"),
                )
        except httpx.HTTPError as e:
            raise HTTPException(status_code=502, detail=f"Deepgram STT failed: {e}") from e

    # ─── TTS Implementations ──────────────────────────────────────────────────

    async def _tts_piper(self, request: TTSRequest) -> TTSResponse:
        """Local Piper only (PIPER_URL), no fallback. Kept for callers of the old per-provider API."""
        return await self._synthesize(request, chain=["piper"])

    async def _tts_elevenlabs(self, request: TTSRequest) -> TTSResponse:
        """ElevenLabs only (eleven_multilingual_v2), no fallback. The voice comes from the persona, the request,
        VOICE_DEFAULT_PERSONA or ELEVENLABS_VOICE_ID — never a built-in default (503 when none is configured)."""
        return await self._synthesize(request, chain=["elevenlabs"])


# ─── Singleton Instance ────────────────────────────────────────────────────

_speech_service: Optional[SpeechService] = None


def get_speech_service() -> SpeechService:
    """Get or create singleton SpeechService."""
    global _speech_service
    if _speech_service is None:
        _speech_service = SpeechService()
    return _speech_service
