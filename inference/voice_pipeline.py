"""
SpiderNet OS — Voice Pipeline (Phase C)

Bidirectional real-time audio pipeline over Twilio Media Streams WebSocket.

Architecture:
  Twilio ──WS──> VoicePipeline.handle_websocket()
                  ├── stt_stream()   → transcription partials/finals (Deepgram live / Whisper)
                  ├── llm_stream()   → token streaming via streaming.stream_generate()
                  ├── tts_stream()   → per-sentence audio synthesis (ElevenLabs / Piper)
                  └── barge_in_detector() → VAD; interrupts TTS + LLM on speech

Latency KPIs (p50 / p95):
  STT partial → final:    150 ms / 300 ms
  LLM first token:        250 ms / 500 ms
  TTS first audio:        300 ms / 600 ms
  End-to-end first audio: 800 ms / 1.5 s
  Barge-in cutoff:        120 ms / 250 ms

Provider chain (configurable via VOICE_STREAMING_STT_PROVIDER / VOICE_STREAMING_TTS_PROVIDER):
  STT: deepgram (preferred) → whisper (fallback)
  TTS: elevenlabs (preferred, eleven_flash_v2_5) → piper (fallback)

Kill-switch:
  voice.streaming=off on the feature flag → VoiceStreamController returns Gather fallback
  (this module is never reached)
"""

from __future__ import annotations

import asyncio
import base64
import json
import logging
import os
import time
from enum import Enum
from typing import Optional

import httpx

from tts_providers import NoPersonaConfigured, default_persona

logger = logging.getLogger(__name__)

# ─── Configuration ────────────────────────────────────────────────────────────

OLLAMA_URL                = os.getenv("OLLAMA_URL", "http://ollama:11434")
DEEPGRAM_KEY              = os.getenv("DEEPGRAM_API_KEY", "")
ELEVENLABS_KEY            = os.getenv("ELEVENLABS_API_KEY", "")
# No built-in voice (the hardcoded "Rachel" default is gone, plan D7 §7): the ElevenLabs voice is
# ELEVENLABS_VOICE_ID or the VOICE_DEFAULT_PERSONA persona when that persona is on ElevenLabs.
ELEVENLABS_VOICE_ID       = os.getenv("ELEVENLABS_VOICE_ID", "")
PIPER_URL                 = os.getenv("PIPER_URL", "http://localhost:5000/synthesize")
WHISPER_URL               = os.getenv("WHISPER_URL", "http://localhost:9000")

STT_PROVIDER              = os.getenv("VOICE_STREAMING_STT_PROVIDER", "deepgram")
TTS_PROVIDER              = os.getenv("VOICE_STREAMING_TTS_PROVIDER", "elevenlabs")
VAD_THRESHOLD             = float(os.getenv("VOICE_VAD_THRESHOLD", "0.6"))
BARGE_IN_CUTOFF_MS        = int(os.getenv("VOICE_BARGE_IN_CUTOFF_MS", "250"))

# Twilio sends μ-law 8 kHz audio chunks every 20 ms
TWILIO_CHUNK_MS           = 20
TWILIO_MULAW_SAMPLE_RATE  = 8000


class PipelineState(Enum):
    IDLE          = "idle"
    LISTENING     = "listening"
    PROCESSING    = "processing"
    SPEAKING      = "speaking"
    BARGE_IN      = "barge_in"
    CLOSED        = "closed"


# ─── VoicePipeline ───────────────────────────────────────────────────────────

class VoicePipeline:
    """
    Manages a single Twilio Media Streams session for one active call.

    Instantiated per-WebSocket connection by the FastAPI /voice/stream endpoint.
    """

    def __init__(self):
        self.state        = PipelineState.IDLE
        self.call_sid:    Optional[str] = None
        self.tenant_id:   Optional[str] = None
        self.stream_sid:  Optional[str] = None
        self.stt_provider = STT_PROVIDER
        self.tts_provider = TTS_PROVIDER

        # Barge-in: set this event to interrupt TTS playback
        self._barge_in_event  = asyncio.Event()
        # Buffer for incoming audio chunks (for STT batching)
        self._audio_buffer:   list[bytes] = []
        self._turn_count:     int = 0
        self._first_audio_ms: Optional[int] = None
        self._start_time:     float = time.monotonic()

    # ─── Main WebSocket handler ──────────────────────────────────────────────

    async def handle_websocket(self, websocket) -> None:
        """
        Entry point called by FastAPI /voice/stream WebSocket route.
        Handles the full Twilio Media Streams protocol lifecycle.
        """
        try:
            await websocket.accept()
            self.state = PipelineState.LISTENING
            logger.info("voice_pipeline.ws_accepted")

            async for message in websocket.iter_text():
                await self._dispatch_message(websocket, message)

        except Exception as exc:
            logger.exception("voice_pipeline.ws_error error=%s", exc)
        finally:
            self.state = PipelineState.CLOSED
            logger.info("voice_pipeline.ws_closed call_sid=%s turns=%d", self.call_sid, self._turn_count)

    async def _dispatch_message(self, websocket, raw_message: str) -> None:
        """Dispatch a Twilio Media Streams message to the appropriate handler."""
        try:
            msg = json.loads(raw_message)
        except json.JSONDecodeError:
            logger.warning("voice_pipeline.invalid_json")
            return

        event = msg.get("event", "")

        if event == "connected":
            logger.info("voice_pipeline.connected protocol=%s", msg.get("protocol"))

        elif event == "start":
            await self._handle_start(msg)

        elif event == "media":
            await self._handle_media(websocket, msg)

        elif event == "stop":
            await self._handle_stop(msg)

        elif event == "mark":
            logger.debug("voice_pipeline.mark name=%s", msg.get("mark", {}).get("name"))

    # ─── Event handlers ──────────────────────────────────────────────────────

    async def _handle_start(self, msg: dict) -> None:
        """Process the 'start' event — call metadata arrives here."""
        start  = msg.get("start", {})
        self.call_sid   = start.get("callSid", "")
        self.stream_sid = start.get("streamSid", "")

        # Extract custom parameters passed from VoiceStreamController.buildStreamTwiML()
        custom_params   = {p["name"]: p["value"] for p in start.get("customParameters", [])}
        self.stt_provider = custom_params.get("stt_provider", STT_PROVIDER)
        self.tts_provider = custom_params.get("tts_provider", TTS_PROVIDER)

        logger.info(
            "voice_pipeline.stream_start call_sid=%s stt=%s tts=%s",
            self.call_sid, self.stt_provider, self.tts_provider,
        )

    async def _handle_media(self, websocket, msg: dict) -> None:
        """
        Process incoming audio chunk from Twilio (μ-law 8 kHz).
        Accumulates into an utterance buffer; when VAD detects end-of-speech,
        triggers the STT → LLM → TTS pipeline.
        """
        media  = msg.get("media", {})
        b64    = media.get("payload", "")
        if not b64:
            return

        audio_bytes = base64.b64decode(b64)
        self._audio_buffer.append(audio_bytes)

        # Simple utterance detector: process every ~400 ms of audio (20 chunks)
        # In production, replace with a proper WebRTC VAD
        if len(self._audio_buffer) >= 20:
            utterance = b"".join(self._audio_buffer)
            self._audio_buffer.clear()

            if self.state == PipelineState.SPEAKING:
                # Barge-in detected
                logger.info("voice_pipeline.barge_in call_sid=%s", self.call_sid)
                self._barge_in_event.set()
                await asyncio.sleep(BARGE_IN_CUTOFF_MS / 1000.0)
                self._barge_in_event.clear()

            self.state = PipelineState.PROCESSING
            asyncio.ensure_future(self._run_turn(websocket, utterance))

    async def _handle_stop(self, msg: dict) -> None:
        """Stream has ended — clean up."""
        logger.info("voice_pipeline.stream_stop call_sid=%s", self.call_sid)
        self.state = PipelineState.CLOSED

    # ─── Turn pipeline ───────────────────────────────────────────────────────

    async def _run_turn(self, websocket, audio_bytes: bytes) -> None:
        """
        Full pipeline for a single caller utterance:
        audio bytes → STT → LLM stream → TTS stream → Twilio media frames
        """
        self._turn_count += 1
        turn_start = time.monotonic()

        try:
            # Step 1: STT
            text = await self._stt(audio_bytes)
            if not text or not text.strip():
                self.state = PipelineState.LISTENING
                return

            stt_ms = int((time.monotonic() - turn_start) * 1000)
            logger.info("voice_pipeline.stt_done text=%r ms=%d", text[:50], stt_ms)

            # Step 2: LLM streaming + TTS streaming in lockstep
            self.state = PipelineState.SPEAKING
            await self._stream_llm_and_tts(websocket, text)

            total_ms = int((time.monotonic() - turn_start) * 1000)
            logger.info("voice_pipeline.turn_done turn=%d total_ms=%d", self._turn_count, total_ms)

        except Exception as exc:
            logger.exception("voice_pipeline.turn_error error=%s", exc)
        finally:
            if self.state != PipelineState.CLOSED:
                self.state = PipelineState.LISTENING

    # ─── STT ─────────────────────────────────────────────────────────────────

    async def _stt(self, audio_bytes: bytes) -> str:
        """Transcribe μ-law audio bytes to text with provider fallback."""
        if self.stt_provider == "deepgram" and DEEPGRAM_KEY:
            try:
                return await self._stt_deepgram(audio_bytes)
            except Exception as exc:
                logger.warning("voice_pipeline.deepgram_failed fallback_whisper error=%s", exc)

        return await self._stt_whisper(audio_bytes)

    async def _stt_deepgram(self, audio_bytes: bytes) -> str:
        """Deepgram streaming STT via REST (for buffered audio chunks)."""
        async with httpx.AsyncClient(timeout=5.0) as client:
            resp = await client.post(
                "https://api.deepgram.com/v1/listen",
                headers={
                    "Authorization": f"Token {DEEPGRAM_KEY}",
                    "Content-Type":  "audio/mulaw;rate=8000",
                },
                content=audio_bytes,
                params={
                    "model":     "nova-2",
                    "language":  "en-US",
                    "punctuate": "true",
                    "encoding":  "mulaw",
                    "sample_rate": "8000",
                },
            )
            resp.raise_for_status()
            data = resp.json()

        result = data.get("results", {}).get("channels", [{}])[0].get("alternatives", [{}])[0]
        return result.get("transcript", "").strip()

    async def _stt_whisper(self, audio_bytes: bytes) -> str:
        """Local Whisper via dedicated service (slower, fallback)."""
        import io
        async with httpx.AsyncClient(timeout=15.0) as client:
            resp = await client.post(
                f"{WHISPER_URL}/transcribe",
                files={"file": ("audio.wav", io.BytesIO(audio_bytes), "audio/wav")},
                data={"language": "en", "model": "base"},
            )
            resp.raise_for_status()
            return resp.json().get("text", "").strip()

    # ─── LLM + TTS streaming ─────────────────────────────────────────────────

    async def _stream_llm_and_tts(self, websocket, caller_text: str) -> None:
        """
        Stream LLM tokens; on each sentence boundary synthesise TTS chunk
        and send to Twilio as base64-encoded media frames.
        Respects barge-in: stops on self._barge_in_event.
        """
        from streaming import StreamingRequest, stream_generate

        system_prompt = "You are a concise voice assistant. Respond in 1-2 sentences."
        messages = [
            {"role": "system", "content": system_prompt},
            {"role": "user",   "content": caller_text},
        ]

        req = StreamingRequest(
            model="qwen3",
            messages=messages,
            voice_mode=True,
            max_tokens=150,
        )

        sentence_count = 0
        first_audio_sent = False

        async for event_str in stream_generate(req):
            if self._barge_in_event.is_set():
                logger.info("voice_pipeline.barge_in_interrupt call_sid=%s", self.call_sid)
                break

            if not event_str.startswith("data: "):
                continue

            try:
                event = json.loads(event_str[6:])
            except json.JSONDecodeError:
                continue

            if event.get("type") == "sentence":
                sentence = event.get("content", "").strip()
                if not sentence:
                    continue

                sentence_count += 1

                # TTS: synthesise to audio
                audio_bytes = await self._tts(sentence)
                if audio_bytes:
                    if not first_audio_sent:
                        first_audio_ms = int((time.monotonic() - self._start_time) * 1000)
                        logger.info(
                            "voice_pipeline.first_audio_ms call_sid=%s ms=%d",
                            self.call_sid, first_audio_ms,
                        )
                        first_audio_sent = True

                    await self._send_audio_to_twilio(websocket, audio_bytes)

            elif event.get("type") == "done":
                break
            elif event.get("type") == "error":
                logger.warning("voice_pipeline.llm_error error=%s", event.get("content"))
                break

    # ─── TTS ─────────────────────────────────────────────────────────────────

    async def _tts(self, text: str) -> Optional[bytes]:
        """Synthesise text to audio bytes, with provider fallback chain."""
        if self.tts_provider == "elevenlabs" and ELEVENLABS_KEY:
            try:
                return await self._tts_elevenlabs(text)
            except NoPersonaConfigured as exc:
                logger.error("voice_pipeline.no_tts_persona_configured fallback_piper detail=%s", exc)
            except Exception as exc:
                logger.warning("voice_pipeline.elevenlabs_failed fallback_piper error=%s", exc)

        return await self._tts_piper(text)

    @staticmethod
    def _elevenlabs_voice_id() -> str:
        """ELEVENLABS_VOICE_ID, else the VOICE_DEFAULT_PERSONA persona's ElevenLabs voice. Raises
        NoPersonaConfigured ("no TTS persona configured") when neither names a voice."""
        if ELEVENLABS_VOICE_ID:
            return ELEVENLABS_VOICE_ID
        persona = default_persona(os.environ)
        if persona and persona.get("provider") == "elevenlabs" and persona.get("provider_voice_id"):
            return str(persona["provider_voice_id"])
        raise NoPersonaConfigured(
            "no TTS persona configured: set ELEVENLABS_VOICE_ID or VOICE_DEFAULT_PERSONA to an ElevenLabs persona"
        )

    async def _tts_elevenlabs(self, text: str) -> bytes:
        """ElevenLabs eleven_flash_v2_5 (lowest latency model)."""
        voice_id = self._elevenlabs_voice_id()
        async with httpx.AsyncClient(timeout=8.0) as client:
            resp = await client.post(
                f"https://api.elevenlabs.io/v1/text-to-speech/{voice_id}/stream",
                headers={
                    "xi-api-key": ELEVENLABS_KEY,
                    "Accept":     "audio/mpeg",
                },
                json={
                    "text":     text,
                    "model_id": "eleven_flash_v2_5",
                    "voice_settings": {
                        "stability":       0.5,
                        "similarity_boost": 0.75,
                    },
                },
            )
            resp.raise_for_status()
            return resp.content

    async def _tts_piper(self, text: str) -> Optional[bytes]:
        """Local Piper TTS fallback."""
        try:
            async with httpx.AsyncClient(timeout=5.0) as client:
                resp = await client.post(
                    PIPER_URL,
                    json={"text": text, "voice": "en_US-lessac-medium", "speed": 1.0},
                )
                if resp.status_code < 500:
                    resp.raise_for_status()
                    return resp.content
        except Exception as exc:
            logger.warning("voice_pipeline.piper_failed error=%s", exc)
        return None

    # ─── Twilio media frame sender ────────────────────────────────────────────

    async def _send_audio_to_twilio(self, websocket, audio_bytes: bytes) -> None:
        """
        Send synthesised audio back to Twilio as Media Streams frames.
        Twilio expects base64-encoded μ-law 8kHz audio in 20ms chunks.

        Note: ElevenLabs / Piper return MP3 or WAV. In production,
        transcode to mulaw-8kHz using audioop or ffmpeg before sending.
        This implementation sends raw bytes with the correct wrapper —
        add `audioop.lin2ulaw` conversion for production use.
        """
        b64_audio = base64.b64encode(audio_bytes).decode("utf-8")

        frame = json.dumps({
            "event":      "media",
            "streamSid":  self.stream_sid or "",
            "media": {
                "payload": b64_audio,
            },
        })

        try:
            await websocket.send_text(frame)
        except Exception as exc:
            logger.warning("voice_pipeline.send_audio_failed error=%s", exc)
