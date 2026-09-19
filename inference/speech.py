"""
SpiderNet OS — Speech-to-Text & Text-to-Speech Service
Wraps Whisper (local), Piper (local), and cloud providers (ElevenLabs, Deepgram).
"""

import io
import os
from typing import Optional

import httpx
from config import OLLAMA_URL
from fastapi import HTTPException
from pydantic import BaseModel

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
    provider: Optional[str] = None  # "piper", "elevenlabs", "twilio"
    voice: Optional[str] = None
    speed: float = 1.0
    format: str = "mp3"  # mp3, wav, pcm


class TTSResponse(BaseModel):
    audio_base64: str
    content_type: str
    provider: str
    duration_estimate: float
    characters: int


# ─── Provider Routing ────────────────────────────────────────────────────────


class SpeechService:
    """Unified STT/TTS service with provider fallback."""

    def __init__(self):
        self.whisper_url = os.getenv("WHISPER_URL", "http://localhost:9000")
        self.elevenlabs_key = os.getenv("ELEVENLABS_API_KEY", "")
        self.elevenlabs_voice = os.getenv("ELEVENLABS_VOICE_ID", "21m00Tcm4TlvDq8ikWAM")
        self.deepgram_key = os.getenv("DEEPGRAM_API_KEY", "")

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
        """Text-to-Speech with provider routing."""
        provider = request.provider or os.getenv("VOICE_TTS_PROVIDER", "piper")

        if provider == "piper":
            return await self._tts_piper(request)
        elif provider == "elevenlabs":
            return await self._tts_elevenlabs(request)
        elif provider == "twilio":
            # Twilio TTS is handled in TwiML, this is passthrough
            return TTSResponse(
                audio_base64="",
                content_type="text/plain",
                provider="twilio",
                duration_estimate=len(request.text) * 0.08,  # ~150 words/min
                characters=len(request.text),
            )
        else:
            raise HTTPException(status_code=400, detail=f"Unknown TTS provider: {provider}")

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
                except:
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
            raise HTTPException(status_code=502, detail=f"Whisper STT failed: {e}")

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
            raise HTTPException(status_code=502, detail=f"Deepgram STT failed: {e}")

    # ─── TTS Implementations ──────────────────────────────────────────────────

    async def _tts_piper(self, request: TTSRequest) -> TTSResponse:
        """Local Piper TTS (free, fast)."""
        try:
            async with httpx.AsyncClient(timeout=10.0) as client:
                resp = await client.post(
                    "http://localhost:5000/synthesize",  # Piper default port
                    json={
                        "text": request.text,
                        "voice": request.voice or "en_US-lessac-medium",
                        "speed": request.speed,
                    },
                )
                # If Piper not running, return mock for development
                if resp.status_code >= 500:
                    return self._tts_mock(request, "piper")

                resp.raise_for_status()
                audio_data = resp.content

                import base64
                return TTSResponse(
                    audio_base64=base64.b64encode(audio_data).decode(),
                    content_type=f"audio/{request.format}",
                    provider="piper",
                    duration_estimate=len(request.text.split()) * 0.08 * (1 / request.speed),
                    characters=len(request.text),
                )
        except httpx.ConnectError:
            # Piper not available, return mock for development
            return self._tts_mock(request, "piper")
        except httpx.HTTPError as e:
            raise HTTPException(status_code=502, detail=f"Piper TTS failed: {e}")

    async def _tts_elevenlabs(self, request: TTSRequest) -> TTSResponse:
        """ElevenLabs cloud TTS (high quality, low latency model)."""
        if not self.elevenlabs_key:
            raise HTTPException(status_code=500, detail="ElevenLabs API key not configured")

        try:
            async with httpx.AsyncClient(timeout=10.0) as client:
                resp = await client.post(
                    f"https://api.elevenlabs.io/v1/text-to-speech/{self.elevenlabs_voice}/stream",
                    headers={
                        "xi-api-key": self.elevenlabs_key,
                        "Accept": "audio/mpeg",
                    },
                    json={
                        "text": request.text,
                        "model_id": "eleven_flash_v2_5",  # Low latency
                        "voice_settings": {
                            "stability": 0.5,
                            "similarity_boost": 0.75,
                        },
                    },
                )
                resp.raise_for_status()
                audio_data = resp.content

                import base64
                return TTSResponse(
                    audio_base64=base64.b64encode(audio_data).decode(),
                    content_type="audio/mpeg",
                    provider="elevenlabs",
                    duration_estimate=len(request.text.split()) * 0.08,
                    characters=len(request.text),
                )
        except httpx.HTTPError as e:
            raise HTTPException(status_code=502, detail=f"ElevenLabs TTS failed: {e}")

    def _tts_mock(self, request: TTSRequest, provider: str) -> TTSResponse:
        """Mock TTS for development when services unavailable."""
        return TTSResponse(
            audio_base64="",  # Empty audio
            content_type="audio/wav",
            provider=f"{provider}-mock",
            duration_estimate=len(request.text.split()) * 0.08,
            characters=len(request.text),
        )


# ─── Singleton Instance ────────────────────────────────────────────────────

_speech_service: Optional[SpeechService] = None


def get_speech_service() -> SpeechService:
    """Get or create singleton SpeechService."""
    global _speech_service
    if _speech_service is None:
        _speech_service = SpeechService()
    return _speech_service
