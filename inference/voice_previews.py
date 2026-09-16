"""
SpiderNet OS — Atlas voice options (plan D7 §7, slice 0: the listening file). v2 — no Azure by default.

Renders three fixed sample lines (a greeting, a verdict, an apology — written in the Atlas voice)
for every persona in inference/voice_personas.yaml, using whichever TTS keys are present, then writes:

    <out>/<slug>/{greeting,verdict,apology}.mp3   (Intron and Piper return WAV, so .wav there)
    <out>/<slug>/take-N.mp3 + design.json         ElevenLabs Voice Design candidates (all three lines in one clip)
    <out>/manifest.json                            persona fields + which samples exist + errors + discovery
    <out>/index.html                               the self-contained "Atlas voice options" page

Usage:
    python -m inference.voice_previews --out build/voice-previews [--only elevenlabs|fishaudio|intron|piper]
                                       [--personas inference/voice_personas.yaml] [--env-file inference/.env]
                                       [--no-discover] [--include-azure]
    (from inside the inference directory / on the prod host:  python -m voice_previews ...)

Env:
    ELEVENLABS_API_KEY                       ElevenLabs TTS, shared-voices discovery, Voice Design
    FISH_AUDIO_API_KEY                       Fish Audio TTS (model header s2.1-pro) + library search
    INTRON_API_KEY                           Intron Sahara TTS (enqueue + poll)
    PIPER_URL                                local Piper HTTP server (POST /synthesize, as speech.py)
    AZURE_SPEECH_KEY + AZURE_SPEECH_REGION   only with --include-azure (disabled by default, Jason 2026-09-16)

A provider whose key is absent is skipped with one clear log line and its cards are rendered
greyed out with "needs <ENV>". Idempotent: sample files that already exist are never re-rendered.
"""
from __future__ import annotations

import argparse
import base64
import datetime as _dt
import html
import json
import logging
import os
import sys
import time
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
# Same flat-import bootstrap as doctor.py, so `from doctor import ...` resolves whether this runs
# as `python -m inference.voice_previews` (repo root) or `python -m voice_previews` (prod layout).
if str(HERE) not in sys.path:
    sys.path.insert(0, str(HERE))
DEFAULT_PERSONAS_PATH = HERE / "voice_personas.yaml"
DEFAULT_TEMPLATE_PATH = HERE / "templates" / "voice-options.html"
PAGE_TITLE = "Atlas voice options"

# The Atlas voice: wise, crisp, warm. Same three lines for every persona so the listener
# compares voices, not scripts. 12–20 words each.
SAMPLE_LINES = {
    "greeting": "Good morning. I have read the week's numbers; three things need you, and the rest can wait.",
    "verdict": "The pipeline is healthy, but two deals have gone quiet. I recommend we follow up today.",
    "apology": "I was wrong about the invoice date. I have corrected it, and here is what changed.",
}
SAMPLE_ORDER = ("greeting", "verdict", "apology")
# ElevenLabs Voice Design needs 100–1000 characters of text, so a design take carries all three lines.
DESIGN_TEXT = " ".join(SAMPLE_LINES[key] for key in SAMPLE_ORDER)

PROVIDER_ORDER = ("elevenlabs", "fishaudio", "intron", "piper", "azure")
PROVIDER_LABELS = {
    "elevenlabs": "ElevenLabs",
    "fishaudio": "Fish Audio",
    "intron": "Intron Sahara",
    "piper": "Piper (local dev baseline)",
    "azure": "Azure Speech (disabled by default)",
}
PROVIDER_NEEDS = {
    "elevenlabs": "ELEVENLABS_API_KEY",
    "fishaudio": "FISH_AUDIO_API_KEY",
    "intron": "INTRON_API_KEY",
    "piper": "PIPER_URL",
    "azure": "AZURE_SPEECH_KEY + AZURE_SPEECH_REGION",
}
DISABLED_BY_DEFAULT = ("azure",)

# Per-seat selects on the page. Keys match voice_personas.yaml recommended_for values.
SEATS = (
    ("character:hannah", "Hannah — guide"),
    ("character:forge", "Forge — builder"),
    ("character:sentinel", "Sentinel — watcher"),
    ("character:prism", "Prism — analyst"),
    ("character:nexus", "Nexus — executor"),
    ("board:offer_architect", "Board — The Offer Architect"),
    ("board:producer", "Board — The Producer"),
    ("board:leverage_philosopher", "Board — The Leverage Philosopher"),
    ("board:compounder", "Board — The Compounder"),
    ("board:greenlight", "Board — The Greenlight"),
)

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

log = logging.getLogger("voice_previews")


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
        # Same request shape as SpeechService._tts_piper in speech.py.
        resp = self.client.post(
            f"{self.url.rstrip('/')}/synthesize",
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


# ─── Catalogue + rendering ───────────────────────────────────────────────────


def load_personas(path: Path | str = DEFAULT_PERSONAS_PATH) -> list[dict]:
    if yaml is None:
        raise SystemExit("voice_previews needs PyYAML to read the catalogue: pip install pyyaml")
    with open(path, "r", encoding="utf-8") as fh:
        data = yaml.safe_load(fh) or {}
    personas = data.get("personas") or []
    return [dict(p) for p in personas]


def _existing_sample(slug_dir: Path, key: str) -> Optional[Path]:
    for ext in ("mp3", "wav", "opus"):
        candidate = slug_dir / f"{key}.{ext}"
        if candidate.exists() and candidate.stat().st_size > 0:
            return candidate
    return None


def _render_design(persona: dict, entry: dict, renderer: Optional[BaseRenderer], slug_dir: Path,
                   design_text: str) -> tuple[int, int, int]:
    """Voice Design candidates: one call, several takes, persisted with design.json. Returns
    (rendered, reused, failed) counts."""
    slug = entry["slug"]
    entry["samples"] = {key: None for key in SAMPLE_ORDER}  # a take carries all three lines in one clip
    entry["takes"] = []
    meta_path = slug_dir / "design.json"

    if meta_path.exists():
        meta = json.loads(meta_path.read_text(encoding="utf-8"))
        takes = [t for t in meta.get("takes") or [] if (slug_dir / Path(t.get("file", "")).name).exists()]
        entry["design"] = meta
        entry["takes"] = takes
        entry["status"] = "rendered" if takes else "failed"
        return 0, len(takes), 0

    if renderer is None or not renderer.available:
        entry["missing_env"] = renderer.reason if renderer else f"unknown provider '{persona.get('provider')}'"
        entry["status"] = "skipped"
        return 0, 0, 0
    if not hasattr(renderer, "design"):
        entry["errors"].append("design: this provider has no voice design endpoint")
        entry["status"] = "failed"
        return 0, 0, 1

    try:
        takes, used_text = renderer.design(persona, design_text)  # type: ignore[attr-defined]
        slug_dir.mkdir(parents=True, exist_ok=True)
        saved = []
        for take in takes:
            filename = f"take-{take['index']}.{take['ext']}"
            (slug_dir / filename).write_bytes(take["audio"])
            saved.append({
                "index": take["index"],
                "file": f"{slug}/{filename}",
                "generated_voice_id": take.get("generated_voice_id"),
                "duration_secs": take.get("duration_secs"),
                "media_type": take.get("media_type"),
                "bytes": len(take["audio"]),
            })
        meta = {
            "voice_description": persona.get("voice_description"),
            "text": used_text,
            "model_id": persona.get("design_model_id") or ELEVENLABS_DESIGN_MODEL,
            "seed": persona.get("seed"),
            "generated_at": _dt.datetime.now(_dt.timezone.utc).isoformat(timespec="seconds"),
            "takes": saved,
            "created": False,
            "create_with": ELEVENLABS_CREATE_ENDPOINT
            + " {voice_name, voice_description, generated_voice_id} -- run only for the chosen take",
        }
        meta_path.write_text(json.dumps(meta, indent=2, ensure_ascii=False), encoding="utf-8")
        entry["design"] = meta
        entry["takes"] = saved
        entry["status"] = "rendered"
        log.info("%s: designed %s -> %d take(s)", persona.get("provider"), slug, len(saved))
        return len(saved), 0, 0
    except Exception as exc:  # noqa: BLE001
        entry["errors"].append(f"design: {exc}")
        entry["status"] = "failed"
        log.warning("%s: design for %s failed: %s", persona.get("provider"), slug, exc)
        return 0, 0, 1


def render_all(
    personas: Sequence[dict],
    renderers: Mapping[str, BaseRenderer],
    out_dir: Path | str,
    only: Optional[Sequence[str]] = None,
    sample_lines: Optional[Mapping[str, str]] = None,
    include_azure: bool = False,
    design_text: str = DESIGN_TEXT,
) -> dict:
    """Render every sample that does not exist yet; return the manifest (not yet written)."""
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    lines = dict(sample_lines or SAMPLE_LINES)

    enabled = set(only) if only else set(PROVIDER_ORDER)
    # Providers disabled by default stay off unless --include-azure or an explicit --only names them.
    disabled = [p for p in DISABLED_BY_DEFAULT if not include_azure and not (only and p in only)]
    enabled.difference_update(disabled)
    selected = [dict(p) for p in personas if p.get("provider") in enabled]

    manifest: dict = {
        "title": PAGE_TITLE,
        "generated_at": _dt.datetime.now(_dt.timezone.utc).isoformat(timespec="seconds"),
        "sample_lines": lines,
        "design_text": design_text,
        "providers": {},
        "disabled_providers": disabled,
        "personas": [],
        "discovery": {},
        "errors": [],
    }

    for provider in PROVIDER_ORDER:
        renderer = renderers.get(provider)
        if renderer is None or provider not in enabled:
            continue
        manifest["providers"][provider] = {
            "label": PROVIDER_LABELS.get(provider, provider),
            "available": renderer.available,
            "reason": renderer.reason,
            **renderer.info,
        }
        if not renderer.available:
            log.info("%s: skipped -- %s", provider, renderer.reason)
            continue
        try:
            renderer.prepare(selected, manifest)
        except Exception as exc:  # noqa: BLE001
            manifest["errors"].append(f"{provider} prepare failed: {exc}")
            log.warning("%s: prepare failed: %s", provider, exc)
        manifest["providers"][provider].update(renderer.info)

    rendered = reused = failed = 0
    for persona in selected:
        slug = str(persona.get("slug") or "").strip()
        provider = str(persona.get("provider") or "")
        entry = dict(persona)
        entry["samples"] = {}
        entry["errors"] = []
        renderer = renderers.get(provider)
        slug_dir = out / slug

        if persona.get("kind") == "design":
            r, s, f = _render_design(persona, entry, renderer, slug_dir, design_text)
            rendered += r
            reused += s
            failed += f
            manifest["personas"].append(entry)
            continue

        for key in SAMPLE_ORDER:
            existing = _existing_sample(slug_dir, key)
            if existing is not None:
                entry["samples"][key] = {
                    "file": f"{slug}/{existing.name}",
                    "exists": True,
                    "bytes": existing.stat().st_size,
                    "reused": True,
                }
                reused += 1
                continue
            if renderer is None or not renderer.available:
                entry["samples"][key] = None
                entry["missing_env"] = renderer.reason if renderer else f"unknown provider '{provider}'"
                continue
            try:
                audio, ext = renderer.render(persona, lines[key])
                if not audio:
                    raise RenderError("provider returned empty audio")
                slug_dir.mkdir(parents=True, exist_ok=True)
                target = slug_dir / f"{key}.{ext}"
                target.write_bytes(audio)
                entry["samples"][key] = {
                    "file": f"{slug}/{target.name}",
                    "exists": True,
                    "bytes": len(audio),
                    "reused": False,
                }
                rendered += 1
                log.info("%s: rendered %s/%s.%s (%d bytes)", provider, slug, key, ext, len(audio))
            except Exception as exc:  # noqa: BLE001 — one bad sample never stops the run
                entry["samples"][key] = None
                entry["errors"].append(f"{key}: {exc}")
                failed += 1
                log.warning("%s: %s/%s failed: %s", provider, slug, key, exc)

        have = sum(1 for v in entry["samples"].values() if v)
        if have == len(SAMPLE_ORDER):
            entry["status"] = "rendered"
        elif have:
            entry["status"] = "partial"
        elif entry["errors"]:
            entry["status"] = "failed"
        else:
            entry["status"] = "skipped"
        manifest["personas"].append(entry)

    manifest["summary"] = {
        "personas": len(selected),
        "rendered": rendered,
        "reused": reused,
        "failed": failed,
    }
    return manifest


def write_manifest(out_dir: Path | str, manifest: dict) -> Path:
    path = Path(out_dir) / "manifest.json"
    path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")
    return path


# ─── Page rendering ──────────────────────────────────────────────────────────


def _esc(value) -> str:
    return html.escape("" if value is None else str(value), quote=True)


def _status_html(providers: Mapping[str, dict]) -> str:
    parts = []
    for provider in PROVIDER_ORDER:
        info = providers.get(provider)
        if info is None:
            continue
        label = _esc(info.get("label") or provider)
        if info.get("available"):
            extra = ""
            if info.get("model"):
                extra = f" ({_esc(info['model'])})"
            elif info.get("region"):
                extra = f" ({_esc(info['region'])})"
            elif info.get("url"):
                extra = f" ({_esc(info['url'])})"
            parts.append(f'<span class="ok">{label}: ready{extra}</span>')
        else:
            parts.append(f'<span class="no">{label}: {_esc(info.get("reason") or "not configured")}</span>')
    return "".join(parts)


def _sample_lines_html(lines: Mapping[str, str]) -> str:
    return "".join(
        f"<dt>{_esc(key)}</dt><dd>{_esc(lines.get(key, ''))}</dd>" for key in SAMPLE_ORDER
    )


def _cost_label(entry: Mapping) -> str:
    cost = entry.get("cost_per_1k_chars")
    if cost is None:
        return "cost: unverified"
    if cost == 0:
        return "cost: free (local)"
    return f"cost: ${cost:g} / 1k chars"


def choice_value(entry: Mapping, take: Optional[Mapping] = None) -> str:
    """Radio/select value: the slug, or slug@generated_voice_id for a Voice Design take."""
    slug = str(entry.get("slug") or "")
    if take and take.get("generated_voice_id"):
        return f"{slug}@{take['generated_voice_id']}"
    return slug


def _card_html(entry: Mapping, provider_available: bool) -> str:
    slug = _esc(entry.get("slug"))
    disabled = not provider_available
    classes = "card disabled" if disabled else "card"
    is_design = entry.get("kind") == "design"
    tags = "".join(f'<span class="tag">{_esc(t)}</span>' for t in entry.get("tags") or [])
    rec = ", ".join(entry.get("recommended_for") or [])
    meta = [
        _esc(entry.get("language") or ""),
        _esc(entry.get("gender") or ""),
        f"style: {_esc(entry.get('style'))}" if entry.get("style") else "",
        _cost_label(entry),
    ]
    if rec:
        meta.append(f"recommended: {_esc(rec)}")
    if entry.get("voice_id_found") is False:
        meta.append('<span class="err">voice id not found in region</span>')
    elif entry.get("voice_id_found") is True:
        meta.append("voice id verified")

    blocks = []
    if is_design:
        blocks.append(f'<p class="desc">{_esc(entry.get("voice_description"))}</p>')
        takes = entry.get("takes") or []
        if takes:
            for take in takes:
                value = _esc(choice_value(entry, take))
                blocks.append(
                    f'<div class="sample"><span class="label">Take {take.get("index")}</span>'
                    f'<audio controls preload="none" src="{_esc(take.get("file"))}"></audio>'
                    f'<label class="pick"><input type="radio" name="atlas" value="{value}"> Atlas</label></div>'
                )
            blocks.append(
                '<p class="hint">Generated previews (all three lines in one clip). Choosing a take records its '
                "generated_voice_id; the voice is only created in the account with POST /v1/text-to-voice later.</p>"
            )
        elif disabled:
            blocks.append(f'<div class="sample"><span class="label">Design</span><span class="missing">{_esc(entry.get("missing_env") or "needs a key")}</span></div>')
        else:
            error = next((e for e in entry.get("errors") or [] if e.startswith("design:")), None)
            reason = error.split(":", 1)[1].strip() if error else "not rendered"
            blocks.append(f'<div class="sample"><span class="label">Design</span><span class="err">{_esc(reason)}</span></div>')
    else:
        for key in SAMPLE_ORDER:
            sample = (entry.get("samples") or {}).get(key)
            label = f'<span class="label">{_esc(key.capitalize())}</span>'
            if sample and sample.get("file"):
                blocks.append(
                    f'<div class="sample">{label}<audio controls preload="none" src="{_esc(sample["file"])}"></audio></div>'
                )
            elif disabled:
                blocks.append(
                    f'<div class="sample">{label}<span class="missing">{_esc(entry.get("missing_env") or "needs a key")}</span></div>'
                )
            else:
                error = next((e for e in entry.get("errors") or [] if e.startswith(f"{key}:")), None)
                reason = error.split(":", 1)[1].strip() if error else "not rendered"
                blocks.append(f'<div class="sample">{label}<span class="err">{_esc(reason)}</span></div>')
    if entry.get("preview_url"):
        blocks.append(
            f'<div class="sample"><span class="label">Library</span>'
            f'<audio controls preload="none" src="{_esc(entry["preview_url"])}"></audio></div>'
        )

    if is_design:
        voice_line = "voice design — created only when chosen"
    else:
        voice_line = entry.get("provider_voice_id") or "voice id: to be discovered"
    choose = ""
    if not (is_design and entry.get("takes")):
        radio_disabled = " disabled" if disabled or (is_design and not entry.get("takes")) else ""
        choose = f'<label class="choose"><input type="radio" name="atlas" value="{slug}"{radio_disabled}> Choose for Atlas</label>'
    return (
        f'<article class="{classes}" data-slug="{slug}" data-provider="{_esc(entry.get("provider"))}">'
        f'<div class="card-head"><h3>{_esc(entry.get("display_name"))}</h3>'
        f"<code>{_esc(voice_line)}</code></div>"
        f'<div class="tags">{tags}</div>'
        f'<div class="meta">{"".join(f"<span>{m}</span>" for m in meta if m)}</div>'
        f'<div class="samples">{"".join(blocks)}</div>'
        f"{choose}"
        f"</article>"
    )


def _groups_html(manifest: Mapping) -> str:
    providers = manifest.get("providers") or {}
    entries = manifest.get("personas") or []
    by_provider: dict = {}
    for entry in entries:
        by_provider.setdefault(entry.get("provider") or "other", []).append(entry)

    order = [p for p in PROVIDER_ORDER if p in by_provider] + [p for p in by_provider if p not in PROVIDER_ORDER]
    sections = []
    for provider in order:
        info = providers.get(provider) or {}
        available = bool(info.get("available"))
        label = _esc(info.get("label") or PROVIDER_LABELS.get(provider, provider))
        note = "" if available else f' <span class="missing">— {_esc(info.get("reason") or "not configured")}</span>'
        by_accent: dict = {}
        for entry in by_provider[provider]:
            by_accent.setdefault(entry.get("accent") or "Other", []).append(entry)
        accent_blocks = []
        for accent, group in by_accent.items():
            cards = "".join(_card_html(e, available) for e in group)
            accent_blocks.append(
                f'<div class="accent">{_esc(accent)} · {len(group)}</div><div class="grid">{cards}</div>'
            )
        sections.append(
            f'<section data-provider="{_esc(provider)}"><h2>{label}{note}</h2>{"".join(accent_blocks)}</section>'
        )
    return "".join(sections)


def _seats_html(manifest: Mapping) -> str:
    entries = manifest.get("personas") or []
    providers = manifest.get("providers") or {}
    selects = []
    for seat, label in SEATS:
        options = ['<option value="">— none —</option>']
        preselected = False
        for entry in entries:
            available = bool((providers.get(entry.get("provider")) or {}).get("available"))
            suffix = "" if available else " (no sample)"
            recommended = seat in (entry.get("recommended_for") or [])
            takes = entry.get("takes") or [] if entry.get("kind") == "design" else []
            variants = [(choice_value(entry, t), f" — take {t.get('index')}") for t in takes] or [(choice_value(entry), "")]
            for value, take_label in variants:
                selected = ""
                if not preselected and recommended:
                    selected = " selected"  # first persona in catalogue order recommended for this seat wins
                    preselected = True
                options.append(
                    f'<option value="{_esc(value)}"{selected}>{_esc(entry.get("display_name"))}{_esc(take_label)}{_esc(suffix)}</option>'
                )
        selects.append(
            f'<label>{_esc(label)}<select data-seat="{_esc(seat)}">{"".join(options)}</select></label>'
        )
    return "".join(selects)


def render_page(manifest: Mapping, template: str) -> str:
    """Fill the voice-options.html template. Everything is inline; no external assets."""
    replacements = {
        "__TITLE__": _esc(manifest.get("title") or PAGE_TITLE),
        "__GENERATED_AT__": _esc(manifest.get("generated_at") or ""),
        "__PERSONA_COUNT__": str(len(manifest.get("personas") or [])),
        "__PROVIDER_STATUS__": _status_html(manifest.get("providers") or {}),
        "__SAMPLE_LINES__": _sample_lines_html(manifest.get("sample_lines") or SAMPLE_LINES),
        "__GROUPS__": _groups_html(manifest),
        "__SEATS__": _seats_html(manifest),
    }
    page = template
    for token, value in replacements.items():
        page = page.replace(token, value)
    return page


def write_page(out_dir: Path | str, manifest: Mapping, template_path: Path | str = DEFAULT_TEMPLATE_PATH) -> Path:
    template = Path(template_path).read_text(encoding="utf-8")
    path = Path(out_dir) / "index.html"
    path.write_text(render_page(manifest, template), encoding="utf-8")
    return path


# ─── CLI ─────────────────────────────────────────────────────────────────────


def _parse_only(value: Optional[str]) -> Optional[list[str]]:
    if not value:
        return None
    wanted = [v.strip().lower() for v in value.split(",") if v.strip()]
    unknown = [w for w in wanted if w not in PROVIDER_ORDER]
    if unknown:
        raise SystemExit(f"--only accepts {', '.join(PROVIDER_ORDER)}; got {', '.join(unknown)}")
    return wanted


def main(argv: Optional[Sequence[str]] = None, environ: Optional[Mapping[str, str]] = None,
         client: Optional[httpx.Client] = None) -> int:
    parser = argparse.ArgumentParser(prog="voice_previews", description=PAGE_TITLE)
    parser.add_argument("--out", required=True, help="output directory (samples, manifest.json, index.html)")
    parser.add_argument("--only", default=None, help="restrict to providers: elevenlabs|fishaudio|intron|piper|azure (comma-separated ok)")
    parser.add_argument("--personas", default=str(DEFAULT_PERSONAS_PATH), help="persona catalogue YAML")
    parser.add_argument("--template", default=str(DEFAULT_TEMPLATE_PATH), help="page template")
    parser.add_argument("--no-discover", action="store_true", help="skip ElevenLabs / Fish Audio library discovery")
    parser.add_argument("--include-azure", action="store_true", help="enable the Azure renderer (disabled by default)")
    parser.add_argument("--env-file", default=None, help="dotenv file with the TTS keys (fills names the process env lacks)")
    args = parser.parse_args(argv)

    logging.basicConfig(level=logging.INFO, format="%(message)s")
    env = dict(environ if environ is not None else os.environ)
    if args.env_file:
        from doctor import apply_env_file, load_env_file  # same directory; same parsing rules as the doctor

        applied = apply_env_file(load_env_file(args.env_file), env)
        log.info("env-file %s: applied %d name(s) not already in the environment", args.env_file, len(applied))
    only = _parse_only(args.only)

    personas = load_personas(args.personas)
    if not personas:
        log.error("no personas in %s", args.personas)
        return 1

    own_client = client is None
    client = client or httpx.Client(timeout=60.0)
    try:
        renderers = build_renderers(env, client, discover=not args.no_discover)
        manifest = render_all(personas, renderers, args.out, only=only, include_azure=args.include_azure)
    finally:
        if own_client:
            client.close()

    manifest_path = write_manifest(args.out, manifest)
    page_path = write_page(args.out, manifest, args.template)

    summary = manifest["summary"]
    log.info(
        "%d personas: %d samples rendered, %d reused, %d failed",
        summary["personas"], summary["rendered"], summary["reused"], summary["failed"],
    )
    for provider in PROVIDER_ORDER:
        info = manifest["providers"].get(provider)
        if info and not info["available"]:
            log.info("%s cards greyed out: %s", provider, info["reason"])
    if manifest["disabled_providers"]:
        log.info("disabled by default (pass --include-azure): %s", ", ".join(manifest["disabled_providers"]))
    log.info("manifest: %s", manifest_path)
    log.info("page:     %s", page_path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
