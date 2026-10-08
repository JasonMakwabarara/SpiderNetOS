"""
Unit tests for inference/tts_providers.py (plan D7 §7): the provider calls shared by voice_previews.py and
SpeechService.synthesize(), persona resolution without any built-in voice, and the
persona provider -> elevenlabs -> piper chain. Offline: every HTTP call goes through httpx.MockTransport.
"""
import asyncio
import base64
import json
from pathlib import Path

import httpx
import pytest
from fastapi import HTTPException

import speech
import tts_providers as tp
import voice_previews as vp

INFERENCE_DIR = Path(tp.__file__).resolve().parent
RACHEL = "21m00Tcm4TlvDq8ikWAM"

ELEVEN_PERSONA = {
    "slug": "elevenlabs-nz-nigerian-man", "provider": "elevenlabs", "provider_voice_id": "gsyHQ9kWCDIipR26RqQ1",
    "language": "en", "output_format": "mp3_44100_128",
}
FISH_PERSONA = {
    "slug": "fishaudio-zimbabwe-male", "provider": "fishaudio", "provider_voice_id": "7e484c38e51d4de5a5bef5bd7e115572",
    "language": "en", "output_format": "mp3",
}


def write_catalogue(tmp_path, personas):
    import yaml

    path = tmp_path / "voice_personas.yaml"
    path.write_text(yaml.safe_dump({"version": 2, "personas": personas}), encoding="utf-8")
    return path


class Recorder:
    """A MockTransport handler that records requests and answers per provider host."""

    def __init__(self, eleven=200, fish=200, piper=200):
        self.status = {"api.elevenlabs.io": eleven, "api.fish.audio": fish, "piper.local": piper}
        self.requests: list[httpx.Request] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        status = self.status.get(request.url.host, 404)
        if status != 200:
            return httpx.Response(status, text=f"{request.url.host} down")
        body = {"api.elevenlabs.io": b"ID3-eleven", "api.fish.audio": b"ID3-fish", "piper.local": b"RIFF-piper"}[request.url.host]
        ctype = "audio/wav" if request.url.host == "piper.local" else "audio/mpeg"
        return httpx.Response(200, content=body, headers={"content-type": ctype})

    def hosts(self):
        return [r.url.host for r in self.requests]


def service(tmp_path, env, handler, personas=()):
    return speech.SpeechService(
        environ=env,
        client_factory=lambda: httpx.Client(transport=httpx.MockTransport(handler)),
        personas_path=write_catalogue(tmp_path, list(personas)),
    )


# ─── the shared provider layer ───────────────────────────────────────────────


def test_voice_previews_reexports_the_shared_provider_classes():
    for name in ("BaseRenderer", "RenderError", "ElevenLabsRenderer", "FishAudioRenderer", "IntronRenderer",
                 "PiperRenderer", "AzureRenderer", "build_renderers", "azure_ssml", "PROVIDER_NEEDS", "PROVIDER_ORDER"):
        assert getattr(vp, name) is getattr(tp, name), name
    assert tp.ELEVENLABS_MODEL == "eleven_multilingual_v2" and tp.FISH_MODEL == "s2.1-pro"
    assert tp.AzureRenderer.disabled_by_default is True and tp.DISABLED_BY_DEFAULT == ("azure",)


def test_no_hardcoded_rachel_voice_left_in_the_plane():
    for name in ("speech.py", "voice_pipeline.py", "tts_providers.py", "voice_previews.py"):
        assert RACHEL not in (INFERENCE_DIR / name).read_text(encoding="utf-8"), name
    assert speech.SpeechService(environ={}).elevenlabs_voice is None


def test_elevenlabs_and_fishaudio_request_contracts():
    rec = Recorder()
    with httpx.Client(transport=httpx.MockTransport(rec)) as client:
        audio, ext = tp.ElevenLabsRenderer("el-key", client).render(ELEVEN_PERSONA, "Good morning.")
        fish_audio, fish_ext = tp.FishAudioRenderer("fish-key", client).render(FISH_PERSONA, "Good morning.")

    eleven, fish = rec.requests
    assert (audio, ext, fish_audio, fish_ext) == (b"ID3-eleven", "mp3", b"ID3-fish", "mp3")
    assert eleven.url.path == "/v1/text-to-speech/gsyHQ9kWCDIipR26RqQ1"
    assert eleven.url.params["output_format"] == "mp3_44100_128" and eleven.headers["xi-api-key"] == "el-key"
    assert json.loads(eleven.content)["model_id"] == "eleven_multilingual_v2"
    assert fish.url.path == "/v1/tts" and fish.headers["model"] == "s2.1-pro"
    assert fish.headers["authorization"] == "Bearer fish-key"
    assert json.loads(fish.content)["reference_id"] == "7e484c38e51d4de5a5bef5bd7e115572"


def test_piper_accepts_a_url_that_already_ends_in_synthesize():
    seen = []

    def handler(request):
        seen.append(request.url.path)
        return httpx.Response(200, content=b"RIFF", headers={"content-type": "audio/wav"})

    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        for url in ("http://piper.local:5000", "http://piper.local:5000/synthesize", "http://piper.local:5000/synthesize/"):
            assert tp.PiperRenderer(url, client).render({"provider": "piper"}, "hi") == (b"RIFF", "wav")
    assert seen == ["/synthesize"] * 3


# ─── persona resolution ─────────────────────────────────────────────────────


def test_normalize_persona_accepts_the_laravel_shape():
    laravel = {"slug": "x", "provider": "ElevenLabs", "voice_id": "abc", "output_format": "mp3"}
    out = tp.normalize_persona(laravel)
    assert out["provider"] == "elevenlabs" and out["provider_voice_id"] == "abc"
    assert out["output_format"] is None  # a bare "mp3" is not an ElevenLabs output_format
    assert tp.normalize_persona(dict(laravel, output_format="mp3_22050_32"))["output_format"] == "mp3_22050_32"
    assert tp.normalize_persona(None) is None
    assert tp.has_voice({"provider": "intron", "provider_voice_id": "en/zulu/male"})
    assert not tp.has_voice({"provider": "intron", "provider_voice_id": None})
    assert tp.has_voice({"provider": "piper"}) and not tp.has_voice({"provider": "fishaudio"})


def test_resolve_persona_order_and_refusal_when_nothing_is_configured(tmp_path):
    path = write_catalogue(tmp_path, [ELEVEN_PERSONA, dict(ELEVEN_PERSONA, slug="design", provider_voice_id=None)])

    # 1. the request persona wins (its provider over the request provider)
    assert tp.resolve_persona({}, persona=FISH_PERSONA, provider="elevenlabs", path=path)["provider"] == "fishaudio"
    # 2. a raw voice needs a provider (request or VOICE_TTS_PROVIDER)
    assert tp.resolve_persona({"VOICE_TTS_PROVIDER": "fishaudio"}, voice="v1", path=path)["provider_voice_id"] == "v1"
    with pytest.raises(tp.NoPersonaConfigured, match="without a provider"):
        tp.resolve_persona({}, voice="v1", path=path)
    # 3. VOICE_DEFAULT_PERSONA is a catalogue slug
    resolved = tp.resolve_persona({"VOICE_DEFAULT_PERSONA": ELEVEN_PERSONA["slug"]}, path=path)
    assert resolved["slug"] == ELEVEN_PERSONA["slug"] and resolved["provider_voice_id"] == "gsyHQ9kWCDIipR26RqQ1"
    with pytest.raises(tp.NoPersonaConfigured, match="not in voice_personas.yaml"):
        tp.resolve_persona({"VOICE_DEFAULT_PERSONA": "nope"}, path=path)
    with pytest.raises(tp.NoPersonaConfigured, match="has no voice id yet"):
        tp.resolve_persona({"VOICE_DEFAULT_PERSONA": "design"}, path=path)
    # 4. an explicitly configured provider voice (env), never a built-in one
    assert tp.resolve_persona({"VOICE_TTS_PROVIDER": "elevenlabs", "ELEVENLABS_VOICE_ID": "env-voice"}, path=path)[
        "provider_voice_id"] == "env-voice"
    assert tp.resolve_persona({}, provider="piper", path=path)["provider_voice_id"] == tp.PIPER_DEFAULT_VOICE
    # 5. nothing configured: refuse
    with pytest.raises(tp.NoPersonaConfigured, match="no TTS persona configured"):
        tp.resolve_persona({}, path=path)
    with pytest.raises(tp.NoPersonaConfigured, match="no TTS persona configured"):
        tp.resolve_persona({"VOICE_TTS_PROVIDER": "elevenlabs"}, path=path)
    with pytest.raises(tp.NoPersonaConfigured, match="has no fishaudio voice id"):
        tp.resolve_persona({}, persona=dict(FISH_PERSONA, provider_voice_id=None), path=path)


# ─── the chain ──────────────────────────────────────────────────────────────


def test_chain_is_persona_provider_then_elevenlabs_then_piper_and_never_azure():
    assert tp.fallback_chain("fishaudio") == ["fishaudio", "elevenlabs", "piper"]
    assert tp.fallback_chain("elevenlabs") == ["elevenlabs", "piper"]
    assert tp.fallback_chain("azure") == ["azure", "elevenlabs", "piper"]
    assert "azure" not in tp.fallback_chain("intron")


def test_synthesize_falls_back_and_records_attempts():
    env = {"ELEVENLABS_API_KEY": "e", "FISH_AUDIO_API_KEY": "f", "PIPER_URL": "http://piper.local:5000"}
    rec = Recorder(fish=500)
    with httpx.Client(transport=httpx.MockTransport(rec)) as client:
        renderers = tp.runtime_renderers(env, client)
        # fishaudio fails, elevenlabs has no voice (no default persona, no ELEVENLABS_VOICE_ID), piper speaks
        result = tp.synthesize("Hello.", FISH_PERSONA, renderers, env=env)
        assert (result.provider, result.ext, result.content_type) == ("piper", "wav", "audio/wav")
        assert [a["provider"] for a in result.attempts] == ["fishaudio", "elevenlabs"]
        assert result.attempts[1]["error"] == "no voice configured" and result.fallback_from == "fishaudio"

        # with an ElevenLabs default persona the chain stops at elevenlabs
        result = tp.synthesize("Hello.", FISH_PERSONA, renderers, env=env, default=ELEVEN_PERSONA)
        assert (result.provider, result.voice_id, result.persona_slug) == ("elevenlabs", "gsyHQ9kWCDIipR26RqQ1", ELEVEN_PERSONA["slug"])
    assert "api.elevenlabs.io" in rec.hosts()


def test_synthesize_skips_unconfigured_providers_and_raises_when_all_fail():
    rec = Recorder(eleven=401)
    with httpx.Client(transport=httpx.MockTransport(rec)) as client:
        renderers = tp.runtime_renderers({"ELEVENLABS_API_KEY": "e"}, client)  # no fish key, no PIPER_URL
        with pytest.raises(tp.SynthesisFailed) as info:
            tp.synthesize("Hello.", FISH_PERSONA, renderers, env={}, default=ELEVEN_PERSONA)
    attempts = {a["provider"]: a["error"] for a in info.value.attempts}
    assert attempts["fishaudio"] == "needs FISH_AUDIO_API_KEY"
    assert "HTTP 401" in attempts["elevenlabs"] and attempts["piper"] == "needs PIPER_URL"
    assert rec.hosts() == ["api.elevenlabs.io"]  # unconfigured providers never touch the network


# ─── SpeechService.synthesize (POST /tts) ───────────────────────────────────


def test_speech_service_routes_on_the_persona_provider(tmp_path):
    rec = Recorder()
    svc = service(tmp_path, {"FISH_AUDIO_API_KEY": "f", "VOICE_TTS_PROVIDER": "elevenlabs"}, rec)
    laravel_persona = {"slug": FISH_PERSONA["slug"], "provider": "fishaudio", "voice_id": FISH_PERSONA["provider_voice_id"]}
    response = asyncio.run(svc.synthesize(speech.TTSRequest(text="Good morning.", provider="elevenlabs",
                                                            voice="ignored", persona=laravel_persona, format="mp3")))
    assert response.provider == "fishaudio" and response.content_type == "audio/mpeg"
    assert base64.b64decode(response.audio_base64) == b"ID3-fish"
    assert response.persona == FISH_PERSONA["slug"] and response.voice == FISH_PERSONA["provider_voice_id"]
    assert response.fallback_from is None and response.characters == len("Good morning.")
    assert rec.hosts() == ["api.fish.audio"]


def test_speech_service_falls_back_to_VOICE_DEFAULT_PERSONA_and_VOICE_TTS_PROVIDER(tmp_path):
    rec = Recorder()
    env = {"ELEVENLABS_API_KEY": "e", "VOICE_DEFAULT_PERSONA": ELEVEN_PERSONA["slug"]}
    svc = service(tmp_path, env, rec, personas=[ELEVEN_PERSONA])
    response = asyncio.run(svc.synthesize(speech.TTSRequest(text="Verdict.")))
    assert (response.provider, response.persona, response.voice) == ("elevenlabs", ELEVEN_PERSONA["slug"], "gsyHQ9kWCDIipR26RqQ1")
    assert rec.requests[0].url.path == "/v1/text-to-speech/gsyHQ9kWCDIipR26RqQ1"

    piper = service(tmp_path, {"VOICE_TTS_PROVIDER": "piper", "PIPER_URL": "http://piper.local:5000"}, Recorder())
    response = asyncio.run(piper.synthesize(speech.TTSRequest(text="Dev line.")))
    assert (response.provider, response.content_type) == ("piper", "audio/wav")


def test_speech_service_503_when_no_persona_is_configured(tmp_path):
    def handler(request):
        raise AssertionError(f"no network expected, got {request.url}")

    svc = service(tmp_path, {"ELEVENLABS_API_KEY": "e"}, handler)
    for request in (speech.TTSRequest(text="Hi."), speech.TTSRequest(text="Hi.", provider="elevenlabs")):
        with pytest.raises(HTTPException) as info:
            asyncio.run(svc.synthesize(request))
        assert info.value.status_code == 503 and "no TTS persona configured" in info.value.detail
    with pytest.raises(HTTPException) as info:
        asyncio.run(svc._tts_elevenlabs(speech.TTSRequest(text="Hi.")))
    assert info.value.status_code == 503


def test_speech_service_502_lists_attempts_400_unknown_provider_and_twilio_passthrough(tmp_path):
    svc = service(tmp_path, {"ELEVENLABS_API_KEY": "e"}, Recorder(eleven=500))
    with pytest.raises(HTTPException) as info:
        asyncio.run(svc.synthesize(speech.TTSRequest(text="Hi.", persona=ELEVEN_PERSONA)))
    assert info.value.status_code == 502
    assert [a["provider"] for a in info.value.detail["attempts"]] == ["elevenlabs", "piper"]

    with pytest.raises(HTTPException) as info:
        asyncio.run(svc.synthesize(speech.TTSRequest(text="Hi.", provider="rachel-tts")))
    assert info.value.status_code == 400

    twilio = asyncio.run(svc.synthesize(speech.TTSRequest(text="Hi.", provider="twilio")))
    assert twilio.provider == "twilio" and twilio.audio_base64 == ""


def test_voice_pipeline_elevenlabs_voice_comes_from_env_or_default_persona(monkeypatch):
    import voice_pipeline

    monkeypatch.setattr(voice_pipeline, "ELEVENLABS_VOICE_ID", "")
    monkeypatch.delenv("VOICE_DEFAULT_PERSONA", raising=False)
    with pytest.raises(tp.NoPersonaConfigured, match="no TTS persona configured"):
        voice_pipeline.VoicePipeline._elevenlabs_voice_id()

    monkeypatch.setenv("VOICE_DEFAULT_PERSONA", "elevenlabs-nz-nigerian-man")  # in the shipped catalogue
    assert voice_pipeline.VoicePipeline._elevenlabs_voice_id() == "gsyHQ9kWCDIipR26RqQ1"

    monkeypatch.setattr(voice_pipeline, "ELEVENLABS_VOICE_ID", "from-env")
    assert voice_pipeline.VoicePipeline._elevenlabs_voice_id() == "from-env"
