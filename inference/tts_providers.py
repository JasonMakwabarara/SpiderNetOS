"""
SpiderNet OS — TTS provider layer (plan D7 §7).

One implementation of every text-to-speech provider call, shared by
    voice_previews.py   the "Atlas voice options" listening page (sync CLI), and
    speech.py           SpeechService.synthesize() behind POST /tts (Atlas speak, telephony).

Providers (endpoints verified against vendor docs 2026-09-16, see voice_personas.yaml):
    elevenlabs  primary     POST /v1/text-to-speech/{voice_id} (eleven_multilingual_v2); shared-voices discovery;
                            Voice Design (eleven_multilingual_ttv_v2)
    fishaudio   alternative POST https://api.fish.audio/v1/tts, header `model` (s2.1-pro) + body reference_id
    intron      alternative POST /tts/v1/enqueue -> poll GET /tts/v1/status/{text_id} -> download audio_path
    piper       dev floor   local HTTP server, POST {PIPER_URL}/synthesize
    azure       dormant     Jason rejected the Azure voices (2026-09-16). Kept for a future catalogue; it only
                            runs for a persona whose provider *is* azure and is never a fallback.

Renderers are synchronous (httpx.Client — MockTransport-testable, no event loop needed); async callers run
them in a worker thread. Runtime synthesis goes persona provider -> elevenlabs -> piper. There is deliberately
no built-in voice: with no persona, no voice and no VOICE_DEFAULT_PERSONA the plane refuses (503) instead of
speaking as a stock stranger (the old hardcoded ElevenLabs "Rachel" default is gone).
"""
from __future__ import annotations

import base64
import logging
import re
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable, Mapping, Optional, Sequence
from urllib.parse import urlparse
from xml.sax.saxutils import escape as xml_escape

import httpx

try:
    import yaml
except ImportError:  # PyYAML is a dev dependency (requirements-dev.txt), not in inference/requirements.txt
    yaml = None

HERE = Path(__file__).resolve().parent
DEFAULT_PERSONAS_PATH = HERE / "voice_personas.yaml"

PROVIDER_ORDER = ("elevenlabs", "fishaudio", "intron", "piper", "azure")
PROVIDER_NEEDS = {
    "elevenlabs": "ELEVENLABS_API_KEY",
    "fishaudio": "FISH_AUDIO_API_KEY",
    "intron": "INTRON_API_KEY",
    "piper": "PIPER_URL",
    "azure": "AZURE_SPEECH_KEY + AZURE_SPEECH_REGION",
}
DISABLED_BY_DEFAULT = ("azure",)
AZURE_OUTPUT_FORMAT = "audio-24khz-48kbitrate-mono-mp3"
ELEVENLABS_BASE = "https://api.elevenlabs.io/v1"
ELEVENLABS_MODEL = "eleven_multilingual_v2"
ELEVENLABS_DESIGN_MODEL = "eleven_multilingual_ttv_v2"
ELEVENLABS_OUTPUT_FORMAT = "mp3_44100_128"
ELEVENLABS_CREATE_ENDPOINT = "POST https://api.elevenlabs.io/v1/text-to-voice"
FISH_BASE = "https://api.fish.audio"
FISH_MODEL = "s2.1-pro"
INTRON_BASE = "https://infer.voice.intron.io/tts/v1"
INTRON_STATUS_OK = "TTS_TEXT_AUDIO_GENERATED"
INTRON_STATUS_FAILED = "TTS_TEXT_AUDIO_PROCESSING_FAILED"

log = logging.getLogger("tts_providers")


class RenderError(RuntimeError):
    """A provider refused or failed to render one sample."""


# ─── Renderers ───────────────────────────────────────────────────────────────


class BaseRenderer:
    """One per provider. `available` is False when the key is missing; `reason` says which."""

    provider = ""

    def __init__(self) -> None:
        self.available = False
        self.reason = ""
        self.info: dict = {}

    def prepare(self, personas: list[dict], manifest: dict) -> None:
        """Optional one-off work before rendering (voice list checks, discovery)."""

    def render(self, persona: dict, text: str) -> tuple[bytes, str]:
        """Return (audio bytes, file extension)."""
        raise NotImplementedError


def azure_ssml(persona: Mapping, text: str) -> str:
    """SSML for Azure Speech: <voice name><mstts:express-as style><prosody>text."""
    lang = persona.get("language") or "en-US"
    voice = str(persona["provider_voice_id"])
    inner = xml_escape(text)

    rate = persona.get("rate")
    pitch = persona.get("pitch")
    prosody = "".join(
        f' {name}="{xml_escape(str(value))}"'
        for name, value in (("rate", rate), ("pitch", pitch))
        if value not in (None, "", "0%")
    )
    if prosody:
        inner = f"<prosody{prosody}>{inner}</prosody>"

    style = persona.get("style")
    if style:
        degree = persona.get("style_degree")
        degree_attr = f' styledegree="{xml_escape(str(degree))}"' if degree is not None else ""
        inner = f'<mstts:express-as style="{xml_escape(str(style))}"{degree_attr}>{inner}</mstts:express-as>'

    return (
        '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" '
        'xmlns:mstts="https://www.w3.org/2001/mstts" xml:lang="%s">'
        '<voice name="%s">%s</voice></speak>' % (xml_escape(lang), xml_escape(voice), inner)
    )


class AzureRenderer(BaseRenderer):
    """Kept for a future catalogue; disabled by default (Jason, 2026-09-16: no Azure voices)."""

    provider = "azure"
    disabled_by_default = True

    def __init__(self, key: str, region: str, client: httpx.Client) -> None:
        super().__init__()
        self.key = key or ""
        self.region = (region or "").strip()
        self.client = client
        self.available = bool(self.key and self.region)
        self.reason = "" if self.available else f"needs {PROVIDER_NEEDS['azure']}"
        self.info = {"region": self.region or None}
        self.known_voices: Optional[set] = None

    @property
    def endpoint(self) -> str:
        return f"https://{self.region}.tts.speech.microsoft.com/cognitiveservices/v1"

    @property
    def voices_list_url(self) -> str:
        return f"https://{self.region}.tts.speech.microsoft.com/cognitiveservices/voices/list"

    def prepare(self, personas: list[dict], manifest: dict) -> None:
        """Confirm every Azure voice id against the region's voices/list."""
        try:
            resp = self.client.get(
                self.voices_list_url, headers={"Ocp-Apim-Subscription-Key": self.key}, timeout=30.0
            )
            resp.raise_for_status()
            self.known_voices = {v.get("ShortName") for v in resp.json() if isinstance(v, dict)}
        except Exception as exc:  # noqa: BLE001 — surfaced in the manifest, never fatal
            manifest["errors"].append(f"azure voices/list failed: {exc}")
            log.warning("azure: voices/list failed (%s); rendering without id verification", exc)
            return
        self.info["voices_listed"] = len(self.known_voices)
        for persona in personas:
            if persona.get("provider") == "azure":
                persona["voice_id_found"] = persona.get("provider_voice_id") in self.known_voices

    def render(self, persona: dict, text: str) -> tuple[bytes, str]:
        if persona.get("voice_id_found") is False:
            raise RenderError(
                f"voice id {persona.get('provider_voice_id')} is not in the {self.region} voices/list"
            )
        resp = self.client.post(
            self.endpoint,
            content=azure_ssml(persona, text).encode("utf-8"),
            headers={
                "Ocp-Apim-Subscription-Key": self.key,
                "Content-Type": "application/ssml+xml",
                "X-Microsoft-OutputFormat": persona.get("output_format") or AZURE_OUTPUT_FORMAT,
                "User-Agent": "spidernet-voice-previews",
            },
            timeout=60.0,
        )
        if resp.status_code != 200:
            raise RenderError(f"azure HTTP {resp.status_code}: {resp.text[:160]}")
        return resp.content, "mp3"


class ElevenLabsRenderer(BaseRenderer):
    provider = "elevenlabs"

    def __init__(self, key: str, client: httpx.Client, discover: bool = True) -> None:
        super().__init__()
        self.key = key or ""
        self.client = client
        self.do_discover = discover
        self.available = bool(self.key)
        self.reason = "" if self.available else f"needs {PROVIDER_NEEDS['elevenlabs']}"
        self.info = {"tts_model": ELEVENLABS_MODEL, "design_model": ELEVENLABS_DESIGN_MODEL}

    def _headers(self) -> dict:
        return {"xi-api-key": self.key}

    def discover(self, accent: str, language: str = "en", page_size: int = 10) -> list[dict]:
        """GET /v1/shared-voices filtered by accent (falls back to free-text search)."""
        params: dict = {"page_size": page_size, "accent": accent}
        if language:
            params["language"] = language
        resp = self.client.get(
            f"{ELEVENLABS_BASE}/shared-voices", params=params, headers=self._headers(), timeout=30.0
        )
        if resp.status_code != 200:
            raise RenderError(f"elevenlabs shared-voices HTTP {resp.status_code}: {resp.text[:160]}")
        voices = resp.json().get("voices", []) or []
        if not voices:
            resp = self.client.get(
                f"{ELEVENLABS_BASE}/shared-voices",
                params={"page_size": page_size, "search": accent},
                headers=self._headers(),
                timeout=30.0,
            )
            voices = resp.json().get("voices", []) if resp.status_code == 200 else []
        return [
            {
                "voice_id": v.get("voice_id"),
                "name": v.get("name"),
                "accent": v.get("accent"),
                "gender": v.get("gender"),
                "age": v.get("age"),
                "language": v.get("language"),
                "use_case": v.get("use_case"),
                "description": v.get("description"),
                "preview_url": v.get("preview_url"),
            }
            for v in voices
            if isinstance(v, dict) and v.get("voice_id")
        ]

    def prepare(self, personas: list[dict], manifest: dict) -> None:
        """Fill placeholder personas (provider_voice_id null + discover hint) from the voice library."""
        if not self.do_discover:
            return
        used = {
            p.get("provider_voice_id")
            for p in personas
            if p.get("provider") == "elevenlabs" and p.get("provider_voice_id")
        }
        discovery = manifest.setdefault("discovery", {}).setdefault("elevenlabs", {})
        for persona in personas:
            if persona.get("provider") != "elevenlabs" or persona.get("provider_voice_id"):
                continue
            if persona.get("kind") == "design":
                continue
            hint = persona.get("discover") or {}
            accent = str(hint.get("accent") or "").strip()
            if not accent:
                continue
            try:
                candidates = self.discover(accent, str(hint.get("language") or "en"))
            except Exception as exc:  # noqa: BLE001
                manifest["errors"].append(f"elevenlabs discovery ({accent}) failed: {exc}")
                log.warning("elevenlabs: discovery for accent '%s' failed: %s", accent, exc)
                continue
            discovery[accent] = candidates
            pick = next((c for c in candidates if c["voice_id"] not in used), None)
            if pick is None:
                persona["discovery_note"] = f"no library voice found for accent '{accent}'"
                log.info("elevenlabs: no library voice found for accent '%s'", accent)
                continue
            used.add(pick["voice_id"])
            persona["provider_voice_id"] = pick["voice_id"]
            persona["display_name"] = f"{pick.get('name') or pick['voice_id']} (library, {accent})"
            persona["gender"] = pick.get("gender") or persona.get("gender")
            persona["preview_url"] = pick.get("preview_url")
            persona["discovered"] = True
            persona["tags"] = [t for t in persona.get("tags", []) if t != "placeholder"] + ["discovered"]
            log.info("elevenlabs: %s -> %s (%s)", persona["slug"], pick["voice_id"], pick.get("name"))

    def render(self, persona: dict, text: str) -> tuple[bytes, str]:
        voice_id = persona.get("provider_voice_id")
        if not voice_id:
            raise RenderError(persona.get("discovery_note") or "no voice id (placeholder not discovered)")
        resp = self.client.post(
            f"{ELEVENLABS_BASE}/text-to-speech/{voice_id}",
            params={"output_format": persona.get("output_format") or ELEVENLABS_OUTPUT_FORMAT},
            headers={**self._headers(), "Accept": "audio/mpeg", "Content-Type": "application/json"},
            json={
                "text": text,
                "model_id": ELEVENLABS_MODEL,
                "voice_settings": {"stability": 0.5, "similarity_boost": 0.75},
            },
            timeout=60.0,
        )
        if resp.status_code != 200:
            raise RenderError(f"elevenlabs HTTP {resp.status_code}: {resp.text[:160]}")
        return resp.content, "mp3"

    def design(self, persona: dict, text: str) -> tuple[list[dict], str]:
        """POST /v1/text-to-voice/design: returns (takes, text used). Each take is a distinct
        generated_voice_id with the audio decoded from audio_base_64. Nothing is created in the
        account — promotion is POST /v1/text-to-voice with the chosen generated_voice_id (PR 5)."""
        description = str(persona.get("voice_description") or "").strip()
        if not description:
            raise RenderError("design persona has no voice_description")
        if not 100 <= len(text) <= 1000:
            raise RenderError(f"design text must be 100-1000 characters (got {len(text)})")
        body: dict = {
            "voice_description": description,
            "text": text,
            "model_id": persona.get("design_model_id") or ELEVENLABS_DESIGN_MODEL,
            "output_format": persona.get("output_format") or ELEVENLABS_OUTPUT_FORMAT,
        }
        if persona.get("seed") is not None:
            body["seed"] = int(persona["seed"])
        if persona.get("guidance_scale") is not None:
            body["guidance_scale"] = float(persona["guidance_scale"])
        resp = self.client.post(
            f"{ELEVENLABS_BASE}/text-to-voice/design",
            headers={**self._headers(), "Content-Type": "application/json"},
            json=body,
            timeout=120.0,
        )
        if resp.status_code != 200:
            raise RenderError(f"elevenlabs design HTTP {resp.status_code}: {resp.text[:160]}")
        data = resp.json()
        takes = []
        for index, preview in enumerate(data.get("previews") or [], start=1):
            if not isinstance(preview, dict):
                continue
            try:
                audio = base64.b64decode(preview.get("audio_base_64") or "")
            except (ValueError, TypeError):
                audio = b""
            if not audio:
                continue
            media_type = preview.get("media_type") or "audio/mpeg"
            takes.append({
                "index": index,
                "generated_voice_id": preview.get("generated_voice_id"),
                "audio": audio,
                "media_type": media_type,
                "duration_secs": preview.get("duration_secs"),
                "ext": "mp3" if "mpeg" in media_type or "mp3" in media_type else "audio",
            })
        if not takes:
            raise RenderError("design returned no previews with audio")
        return takes, str(data.get("text") or text)


class FishAudioRenderer(BaseRenderer):
    """Fish Audio: POST /v1/tts with header `model` (S2.1 Pro) and `reference_id` = library model id."""

    provider = "fishaudio"

    def __init__(self, key: str, client: httpx.Client, model: str = FISH_MODEL, discover: bool = True) -> None:
        super().__init__()
        self.key = key or ""
        self.client = client
        self.model = model
        self.do_discover = discover
        self.available = bool(self.key)
        self.reason = "" if self.available else f"needs {PROVIDER_NEEDS['fishaudio']}"
        self.info = {"model": model}

    def _headers(self) -> dict:
        return {"Authorization": f"Bearer {self.key}"}

    def discover(self, title: str, language: Optional[str] = "en", page_size: int = 10) -> list[dict]:
        """GET https://api.fish.audio/model?title=… (the public library; the docs ask for a Bearer key)."""
        params: dict = {"title": title, "page_size": page_size, "sort_by": "score"}
        if language:
            params["language"] = language
        resp = self.client.get(f"{FISH_BASE}/model", params=params, headers=self._headers(), timeout=30.0)
        if resp.status_code != 200:
            raise RenderError(f"fishaudio model search HTTP {resp.status_code}: {resp.text[:160]}")
        items = resp.json().get("items", []) or []
        out = []
        for item in items:
            if not isinstance(item, dict):
                continue
            model_id = item.get("_id") or item.get("id")
            if not model_id:
                continue
            samples = item.get("samples") or []
            author = item.get("author") or {}
            out.append({
                "id": model_id,
                "title": item.get("title"),
                "description": (item.get("description") or "")[:200],
                "languages": item.get("languages") or [],
                "tags": item.get("tags") or [],
                "author": author.get("nickname") if isinstance(author, dict) else author,
                "like_count": item.get("like_count"),
                "task_count": item.get("task_count"),
                "sample_url": (samples[0].get("audio") if samples and isinstance(samples[0], dict) else None),
            })
        return out

    def prepare(self, personas: list[dict], manifest: dict) -> None:
        if not self.do_discover:
            return
        used = {
            p.get("provider_voice_id")
            for p in personas
            if p.get("provider") == "fishaudio" and p.get("provider_voice_id")
        }
        discovery = manifest.setdefault("discovery", {}).setdefault("fishaudio", {})
        for persona in personas:
            if persona.get("provider") != "fishaudio" or persona.get("provider_voice_id"):
                continue
            hint = persona.get("discover") or {}
            title = str(hint.get("title") or "").strip()
            if not title:
                continue
            try:
                candidates = self.discover(title, hint.get("language") or None)
            except Exception as exc:  # noqa: BLE001
                manifest["errors"].append(f"fishaudio discovery ({title}) failed: {exc}")
                log.warning("fishaudio: discovery for '%s' failed: %s", title, exc)
                continue
            discovery[title] = candidates
            pick = next((c for c in candidates if c["id"] not in used), None)
            if pick is None:
                persona["discovery_note"] = f"no library voice found for '{title}'"
                continue
            used.add(pick["id"])
            persona["provider_voice_id"] = pick["id"]
            persona["display_name"] = f"{pick.get('title') or pick['id']} (library, {title})"
            persona["preview_url"] = pick.get("sample_url")
            persona["discovered"] = True
            persona["tags"] = [t for t in persona.get("tags", []) if t != "placeholder"] + ["discovered"]
            log.info("fishaudio: %s -> %s (%s)", persona["slug"], pick["id"], pick.get("title"))

    def render(self, persona: dict, text: str) -> tuple[bytes, str]:
        reference_id = persona.get("provider_voice_id")
        if not reference_id:
            raise RenderError(persona.get("discovery_note") or "no reference_id (placeholder not discovered)")
        resp = self.client.post(
            f"{FISH_BASE}/v1/tts",
            headers={**self._headers(), "model": persona.get("fish_model") or self.model, "Content-Type": "application/json"},
            json={
                "text": text,
                "reference_id": reference_id,
                "format": "mp3",
                "mp3_bitrate": 128,
                "latency": "normal",
                "normalize": True,
            },
            timeout=120.0,
        )
        if resp.status_code != 200:
            raise RenderError(f"fishaudio HTTP {resp.status_code}: {resp.text[:160]}")
        if not resp.content:
            raise RenderError("fishaudio returned empty audio")
        return resp.content, "mp3"


class IntronRenderer(BaseRenderer):
    """Intron Sahara: POST /tts/v1/enqueue -> data.text_id, poll GET /tts/v1/status/{text_id}
    until TTS_TEXT_AUDIO_GENERATED, then download audio_path. The voice is
    (voice_language, voice_accent, voice_gender) — there is no voice id."""

    provider = "intron"

    def __init__(self, key: str, client: httpx.Client, poll_interval: float = 2.0,
                 poll_timeout: float = 180.0, sleep: Callable[[float], None] = time.sleep) -> None:
        super().__init__()
        self.key = key or ""
        self.client = client
        self.poll_interval = poll_interval
        self.poll_timeout = poll_timeout
        self.sleep = sleep
        self.available = bool(self.key)
        self.reason = "" if self.available else f"needs {PROVIDER_NEEDS['intron']}"
        self.info = {"base": INTRON_BASE}

    def _headers(self) -> dict:
        return {"Authorization": f"Bearer {self.key}"}

    @staticmethod
    def voice_of(persona: Mapping) -> tuple[str, str, str]:
        spec = persona.get("intron") or {}
        language = spec.get("voice_language") or persona.get("language") or "en"
        accent = spec.get("voice_accent")
        gender = spec.get("voice_gender") or persona.get("gender")
        if not accent:
            parts = str(persona.get("provider_voice_id") or "").split("/")
            if len(parts) == 3:
                language, accent, gender = parts
        if not accent or not gender:
            raise RenderError("intron persona needs intron.voice_accent and intron.voice_gender")
        return str(language), str(accent), str(gender)

    def render(self, persona: dict, text: str) -> tuple[bytes, str]:
        language, accent, gender = self.voice_of(persona)
        fmt = persona.get("output_format") or "wav"
        body = {
            "text": text,
            "voice_language": language,
            "voice_accent": accent,
            "voice_gender": gender,
            "output_audio_format": fmt,
        }
        resp = self.client.post(
            f"{INTRON_BASE}/enqueue",
            headers={**self._headers(), "Content-Type": "application/json"},
            json=body,
            timeout=30.0,
        )
        if resp.status_code == 429:
            self.sleep(float(resp.headers.get("retry-after") or self.poll_interval))
            resp = self.client.post(
                f"{INTRON_BASE}/enqueue",
                headers={**self._headers(), "Content-Type": "application/json"},
                json=body,
                timeout=30.0,
            )
        if resp.status_code not in (200, 201, 202):
            raise RenderError(f"intron enqueue HTTP {resp.status_code}: {resp.text[:160]}")
        payload = resp.json()
        data = payload.get("data") if isinstance(payload.get("data"), dict) else {}
        text_id = data.get("text_id") or payload.get("text_id")
        if not text_id:
            raise RenderError("intron enqueue returned no text_id")

        deadline = time.monotonic() + self.poll_timeout
        status = None
        audio_url = None
        while True:
            st = self.client.get(f"{INTRON_BASE}/status/{text_id}", headers=self._headers(), timeout=30.0)
            if st.status_code == 429:
                self.sleep(float(st.headers.get("retry-after") or self.poll_interval))
                continue
            if st.status_code != 200:
                raise RenderError(f"intron status HTTP {st.status_code}: {st.text[:160]}")
            payload = st.json()
            info = payload.get("data") if isinstance(payload.get("data"), dict) else payload
            status = info.get("processing_status") or info.get("status") or payload.get("processing_status")
            if status == INTRON_STATUS_OK:
                audio_url = info.get("audio_path") or payload.get("audio_path") or info.get("audio_url")
                break
            if status == INTRON_STATUS_FAILED:
                raise RenderError(f"intron processing failed: {info.get('error') or info.get('message') or status}")
            if time.monotonic() > deadline:
                raise RenderError(f"intron job {text_id} timed out after {self.poll_timeout:.0f}s (last status {status})")
            self.sleep(self.poll_interval)

        if not audio_url:
            raise RenderError(f"intron status {status} but no audio_path in the response")
        host = (urlparse(audio_url).hostname or "").lower()
        headers = self._headers() if host.endswith("intron.io") else {}
        audio = self.client.get(audio_url, headers=headers, timeout=60.0)
        if audio.status_code != 200 or not audio.content:
            raise RenderError(f"intron audio download HTTP {audio.status_code}")
        return audio.content, ("opus" if fmt == "opus" else "wav")


class PiperRenderer(BaseRenderer):
    provider = "piper"

    def __init__(self, url: str, client: httpx.Client) -> None:
        super().__init__()
        self.url = (url or "").strip()
        self.client = client
        self.available = bool(self.url)
        self.reason = "" if self.available else f"needs {PROVIDER_NEEDS['piper']}"
        self.info = {"url": self.url or None}

    def render(self, persona: dict, text: str) -> tuple[bytes, str]:
        # POST {PIPER_URL}/synthesize {text, voice, speed}; a PIPER_URL that already ends in /synthesize
        # (voice_pipeline.py's historical form) is accepted too.
        base = self.url.rstrip("/")
        if base.endswith("/synthesize"):
            base = base[: -len("/synthesize")]
        resp = self.client.post(
            f"{base}/synthesize",
            json={
                "text": text,
                "voice": persona.get("provider_voice_id") or "en_US-lessac-medium",
                "speed": persona.get("rate") or 1.0,
            },
            timeout=60.0,
        )
        if resp.status_code != 200:
            raise RenderError(f"piper HTTP {resp.status_code}: {resp.text[:160]}")
        content_type = resp.headers.get("content-type", "")
        ext = "mp3" if ("mpeg" in content_type or "mp3" in content_type) else "wav"
        return resp.content, ext


def build_renderers(env: Mapping[str, str], client: httpx.Client, discover: bool = True) -> dict:
    return {
        "elevenlabs": ElevenLabsRenderer(env.get("ELEVENLABS_API_KEY", ""), client, discover=discover),
        "fishaudio": FishAudioRenderer(env.get("FISH_AUDIO_API_KEY", ""), client, discover=discover),
        "intron": IntronRenderer(env.get("INTRON_API_KEY", ""), client),
        "piper": PiperRenderer(env.get("PIPER_URL", ""), client),
        "azure": AzureRenderer(env.get("AZURE_SPEECH_KEY", ""), env.get("AZURE_SPEECH_REGION", ""), client),
    }


# ─── Runtime synthesis: persona resolution + provider chain ─────────────────


PIPER_DEFAULT_VOICE = "en_US-lessac-medium"
# After the persona's own provider: ElevenLabs (the primary cloud voice), then Piper (the local floor).
FALLBACK_PROVIDERS = ("elevenlabs", "piper")
CONTENT_TYPES = {"mp3": "audio/mpeg", "wav": "audio/wav", "opus": "audio/ogg"}
_ELEVENLABS_OUTPUT_FORMAT_RE = re.compile(r"^(mp3|pcm|ulaw|alaw|opus)_\d+(_\d+)?$")
_NO_PERSONA_HINT = "send a persona, or set VOICE_DEFAULT_PERSONA to a slug in voice_personas.yaml"


class NoPersonaConfigured(RuntimeError):
    """Nothing says which voice to speak with (maps to HTTP 503 "no TTS persona configured")."""


class SynthesisFailed(RuntimeError):
    """Every provider in the chain was unavailable or failed; `attempts` lists why, per provider."""

    def __init__(self, attempts: list[dict]) -> None:
        self.attempts = attempts
        summary = "; ".join(f"{a['provider']}: {a['error']}" for a in attempts) or "no provider tried"
        super().__init__(f"all TTS providers failed ({summary})")


@dataclass
class Synthesis:
    audio: bytes
    ext: str
    provider: str
    voice_id: Optional[str]
    persona_slug: Optional[str]
    attempts: list = field(default_factory=list)

    @property
    def content_type(self) -> str:
        return CONTENT_TYPES.get(self.ext, "application/octet-stream")

    @property
    def fallback_from(self) -> Optional[str]:
        """The provider that was asked for when a later one in the chain produced the audio."""
        return self.attempts[0]["provider"] if self.attempts else None


_CATALOGUE_CACHE: dict[tuple[str, float], list[dict]] = {}


def load_catalogue(path: Path | str = DEFAULT_PERSONAS_PATH) -> list[dict]:
    """voice_personas.yaml personas, cached per (path, mtime)."""
    if yaml is None:
        raise NoPersonaConfigured("PyYAML is not installed, so voice_personas.yaml cannot be read")
    path = Path(path)
    try:
        key = (str(path), path.stat().st_mtime)
    except OSError as exc:
        raise NoPersonaConfigured(f"voice persona catalogue not readable: {path} ({exc})") from exc
    if key not in _CATALOGUE_CACHE:
        with open(path, "r", encoding="utf-8") as fh:
            data = yaml.safe_load(fh) or {}
        _CATALOGUE_CACHE.clear()
        _CATALOGUE_CACHE[key] = [dict(p) for p in (data.get("personas") or []) if isinstance(p, dict)]
    return [dict(p) for p in _CATALOGUE_CACHE[key]]


def find_persona(slug: str, path: Path | str = DEFAULT_PERSONAS_PATH) -> Optional[dict]:
    return next((p for p in load_catalogue(path) if p.get("slug") == slug), None)


def normalize_persona(persona: Optional[Mapping], provider: Optional[str] = None,
                      voice: Optional[str] = None) -> Optional[dict]:
    """A Laravel persona ({slug, provider, voice_id | provider_voice_id, language, style, style_degree, rate, pitch,
    output_format, ...}) or a catalogue entry -> the renderer shape (provider_voice_id, lower-case provider)."""
    if not persona:
        return None
    out = dict(persona)
    out["provider"] = str(out.get("provider") or provider or "").strip().lower()
    voice_id = out.get("provider_voice_id") or out.get("voice_id") or voice
    out["provider_voice_id"] = str(voice_id).strip() if voice_id else None
    fmt = out.get("output_format")
    if out["provider"] == "elevenlabs" and fmt and not _ELEVENLABS_OUTPUT_FORMAT_RE.match(str(fmt)):
        out["output_format"] = None  # a bare "mp3" is not an ElevenLabs output_format; the renderer defaults it
    return out


def has_voice(persona: Mapping) -> bool:
    """Whether the persona names a concrete voice for its provider (Piper always has its local model)."""
    provider = persona.get("provider")
    if provider == "piper":
        return True
    if provider == "intron":
        try:
            IntronRenderer.voice_of(persona)
        except RenderError:
            return False
        return True
    return bool(persona.get("provider_voice_id"))


def default_persona(env: Mapping[str, str], path: Path | str = DEFAULT_PERSONAS_PATH) -> Optional[dict]:
    """VOICE_DEFAULT_PERSONA (a catalogue slug) as a renderer persona; None when unset."""
    slug = (env.get("VOICE_DEFAULT_PERSONA") or "").strip()
    if not slug:
        return None
    persona = find_persona(slug, path)
    if persona is None:
        raise NoPersonaConfigured(f"VOICE_DEFAULT_PERSONA '{slug}' is not in voice_personas.yaml")
    return normalize_persona(persona)


def fallback_persona(provider: str, env: Mapping[str, str], default: Optional[Mapping] = None) -> Optional[dict]:
    """The voice a fallback provider speaks with: the default persona when it is on that provider, else the
    provider's explicit env voice (ELEVENLABS_VOICE_ID / PIPER_VOICE). Never a hardcoded cloud voice."""
    if default and default.get("provider") == provider and has_voice(default):
        return dict(default)
    if provider == "elevenlabs":
        voice_id = (env.get("ELEVENLABS_VOICE_ID") or "").strip()
        return {"slug": None, "provider": "elevenlabs", "provider_voice_id": voice_id} if voice_id else None
    if provider == "piper":
        voice_id = (env.get("PIPER_VOICE") or "").strip() or PIPER_DEFAULT_VOICE
        return {"slug": None, "provider": "piper", "provider_voice_id": voice_id, "output_format": "wav"}
    return None


def resolve_persona(env: Mapping[str, str], persona: Optional[Mapping] = None, provider: Optional[str] = None,
                    voice: Optional[str] = None, path: Path | str = DEFAULT_PERSONAS_PATH) -> dict:
    """Request persona -> request voice (+ provider / VOICE_TTS_PROVIDER) -> VOICE_DEFAULT_PERSONA ->
    an explicitly configured provider voice -> NoPersonaConfigured."""
    wanted = (provider or "").strip().lower() or (env.get("VOICE_TTS_PROVIDER") or "").strip().lower() or None

    if persona:
        resolved = normalize_persona(persona, provider=wanted, voice=voice)
        if not resolved["provider"]:
            raise NoPersonaConfigured(f"no TTS persona configured: persona '{resolved.get('slug')}' has no provider")
        if not has_voice(resolved):
            raise NoPersonaConfigured(
                f"no TTS persona configured: persona '{resolved.get('slug')}' has no {resolved['provider']} voice id"
            )
        return resolved

    if voice:
        if wanted is None:
            raise NoPersonaConfigured("no TTS persona configured: a voice was given without a provider "
                                      "(pass provider, or set VOICE_TTS_PROVIDER)")
        return normalize_persona({"slug": None, "provider": wanted, "provider_voice_id": voice})

    default = default_persona(env, path)
    if default is not None and (wanted is None or default["provider"] == wanted):
        if not has_voice(default):
            raise NoPersonaConfigured(
                f"no TTS persona configured: VOICE_DEFAULT_PERSONA '{default.get('slug')}' has no voice id yet"
            )
        return default

    if wanted is not None:
        explicit = fallback_persona(wanted, env)
        if explicit is not None:
            return explicit

    raise NoPersonaConfigured(f"no TTS persona configured: {_NO_PERSONA_HINT}")


def fallback_chain(primary: str, fallbacks: Sequence[str] = FALLBACK_PROVIDERS) -> list[str]:
    chain = [primary]
    for provider in fallbacks:
        if provider not in chain:
            chain.append(provider)
    return chain


def synthesize(text: str, persona: Mapping, renderers: Mapping[str, BaseRenderer], env: Mapping[str, str],
               default: Optional[Mapping] = None, chain: Optional[Sequence[str]] = None) -> Synthesis:
    """Render `text` with the persona's provider, falling back along the chain. Unavailable providers (no key)
    and providers with no voice for this request are skipped and recorded in `attempts`."""
    primary = str(persona.get("provider") or "")
    attempts: list[dict] = []
    for provider in chain or fallback_chain(primary):
        renderer = renderers.get(provider)
        if renderer is None:
            attempts.append({"provider": provider, "error": "unknown provider"})
            continue
        candidate = dict(persona) if provider == primary else fallback_persona(provider, env, default)
        if candidate is None or not has_voice(candidate):
            attempts.append({"provider": provider, "error": "no voice configured"})
            continue
        if not renderer.available:
            attempts.append({"provider": provider, "error": renderer.reason or "not configured"})
            continue
        try:
            audio, ext = renderer.render(candidate, text)
        except (RenderError, httpx.HTTPError) as exc:
            log.warning("tts: %s failed (%s); trying the next provider", provider, exc)
            attempts.append({"provider": provider, "error": str(exc)[:200]})
            continue
        if not audio:
            attempts.append({"provider": provider, "error": "empty audio"})
            continue
        return Synthesis(audio=audio, ext=ext, provider=provider, voice_id=candidate.get("provider_voice_id"),
                         persona_slug=candidate.get("slug"), attempts=attempts)
    raise SynthesisFailed(attempts)


def runtime_renderers(env: Mapping[str, str], client: httpx.Client) -> dict:
    """Renderers for live synthesis: same providers as the listening page, without library discovery."""
    return build_renderers(env, client, discover=False)
