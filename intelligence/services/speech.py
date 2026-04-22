"""
SpiderNet OS — Speech Service Wrapper
Intelligence layer wrapper for calling inference plane STT/TTS.
Used by VoiceAgent and voice tools.
"""

import os
from typing import Optional

import httpx

from config import INFERENCE_URL


class SpeechService:
    """
    Wrapper for inference plane speech endpoints.
    Provides typed interface for agents to call STT/TTS.
    """

    def __init__(self, inference_url: Optional[str] = None):
        self.inference_url = inference_url or INFERENCE_URL or "http://inference:9000"

    async def transcribe(
        self,
        audio_url: Optional[str] = None,
        audio_base64: Optional[str] = None,
        provider: Optional[str] = None,
        language: str = "en",
        model: str = "base",
    ) -> dict:
        """
        Speech-to-Text via inference plane.

        Args:
            audio_url: URL to audio file (wav, mp3)
            audio_base64: Base64-encoded audio data
            provider: "whisper", "deepgram", or "twilio"
            language: Language code (e.g., "en", "es")
            model: Whisper model size ("tiny", "base", "small", "medium", "large")

        Returns:
            {"text": str, "confidence": float, "provider": str, "language": str}
        """
        if not audio_url and not audio_base64:
            raise ValueError("Either audio_url or audio_base64 must be provided")

        payload = {
            "language": language,
            "model": model,
        }
        if audio_url:
            payload["audio_url"] = audio_url
        if audio_base64:
            payload["audio_base64"] = audio_base64
        if provider:
            payload["provider"] = provider

        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                resp = await client.post(
                    f"{self.inference_url}/stt",
                    json=payload,
                )
                resp.raise_for_status()
                return resp.json()
        except httpx.HTTPError as e:
            return {
                "text": "",
                "confidence": 0.0,
                "provider": provider or "unknown",
                "language": language,
                "error": f"STT failed: {e}",
            }

    async def synthesize(
        self,
        text: str,
        provider: Optional[str] = None,
        voice: Optional[str] = None,
        speed: float = 1.0,
        format: str = "mp3",
    ) -> dict:
        """
        Text-to-Speech via inference plane.

        Args:
            text: Text to synthesize
            provider: "piper", "elevenlabs", or "twilio"
            voice: Voice ID or name
            speed: Playback speed (0.5 - 2.0)
            format: Audio format ("mp3", "wav", "pcm")

        Returns:
            {"audio_base64": str, "content_type": str, "provider": str,
             "duration_estimate": float, "characters": int}
        """
        if not text:
            raise ValueError("Text cannot be empty")

        payload = {
            "text": text,
            "speed": speed,
            "format": format,
        }
        if provider:
            payload["provider"] = provider
        if voice:
            payload["voice"] = voice

        try:
            async with httpx.AsyncClient(timeout=10.0) as client:
                resp = await client.post(
                    f"{self.inference_url}/tts",
                    json=payload,
                )
                resp.raise_for_status()
                return resp.json()
        except httpx.HTTPError as e:
            # Return empty response with error info
            return {
                "audio_base64": "",
                "content_type": f"audio/{format}",
                "provider": provider or "unknown",
                "duration_estimate": len(text.split()) * 0.08,
                "characters": len(text),
                "error": f"TTS failed: {e}",
            }

    def estimate_cost(
        self,
        characters: int,
        provider: str = "elevenlabs",
    ) -> float:
        """
        Estimate TTS cost per 1000 characters.

        Pricing (approximate):
        - ElevenLabs: $0.10 per 1000 chars
        - Piper: $0 (local)
        - Twilio: $0.00 (included in call cost)
        """
        rates = {
            "elevenlabs": 0.00010,  # $0.10 per 1000 chars
            "piper": 0.0,  # Free (local)
            "twilio": 0.0,  # Included in call cost
        }
        rate = rates.get(provider, rates["elevenlabs"])
        return characters * rate


# ─── Singleton Instance ────────────────────────────────────────────────────

_speech_service: Optional[SpeechService] = None


def get_speech_service() -> SpeechService:
    """Get or create singleton SpeechService."""
    global _speech_service
    if _speech_service is None:
        _speech_service = SpeechService()
    return _speech_service
