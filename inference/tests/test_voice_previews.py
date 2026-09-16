"""
Unit tests for inference/voice_previews.py (v2: ElevenLabs incl. Voice Design, Fish Audio, Intron,
Piper; Azure disabled by default). httpx is mocked with MockTransport and a fake renderer — no
network, no keys, no server.
"""
import base64
import json
from pathlib import Path

import httpx
import pytest

import voice_previews as vp

CATALOGUE = Path(vp.DEFAULT_PERSONAS_PATH)
TEMPLATE = Path(vp.DEFAULT_TEMPLATE_PATH)

REQUIRED_FIELDS = {
    "slug", "display_name", "provider", "provider_voice_id", "language", "accent", "gender", "style",
    "style_degree", "rate", "pitch", "output_format", "cost_per_1k_chars", "tags", "recommended_for", "consent",
}


class FakeRenderer(vp.BaseRenderer):
    """Returns deterministic bytes; can be told to fail on a sample or to refuse being called."""

    def __init__(self, provider, available=True, fail_on=(), forbid_calls=False, ext="mp3"):
        super().__init__()
        self.provider = provider
        self.available = available
        self.reason = "" if available else f"needs {vp.PROVIDER_NEEDS[provider]}"
        self.fail_on = set(fail_on)
        self.forbid_calls = forbid_calls
        self.ext = ext
        self.calls = []
        self.prepared = False

    def prepare(self, personas, manifest):
        self.prepared = True

    def render(self, persona, text):
        if self.forbid_calls:
            raise AssertionError("render() must not be called for an existing sample")
        self.calls.append((persona["slug"], text))
        key = next(k for k, v in vp.SAMPLE_LINES.items() if v == text)
        if key in self.fail_on:
            raise vp.RenderError(f"simulated {key} failure")
        return f"AUDIO:{persona['slug']}:{key}".encode(), self.ext


class FakeDesignRenderer(FakeRenderer):
    """ElevenLabs-shaped fake with a design() method."""

    def __init__(self, takes=2, fail=False, forbid_calls=False):
        super().__init__("elevenlabs")
        self.n_takes = takes
        self.fail = fail
        self.forbid_calls = forbid_calls
        self.design_calls = []

    def design(self, persona, text):
        if self.forbid_calls:
            raise AssertionError("design() must not be called when design.json exists")
        self.design_calls.append((persona["slug"], text))
        if self.fail:
            raise vp.RenderError("simulated design failure")
        return [
            {"index": i, "generated_voice_id": f"gen-{persona['slug']}-{i}", "audio": f"TAKE{i}".encode(),
             "media_type": "audio/mpeg", "duration_secs": 12.5, "ext": "mp3"}
            for i in range(1, self.n_takes + 1)
        ], text


def base(slug, provider, voice_id, accent, gender, **extra):
    p = {
        "slug": slug, "display_name": slug.replace("-", " ").title(), "provider": provider, "provider_voice_id": voice_id,
        "language": "en", "accent": accent, "gender": gender, "style": None, "style_degree": None, "rate": None,
        "pitch": None, "output_format": "mp3", "cost_per_1k_chars": None, "tags": [provider], "recommended_for": [],
        "consent": {"type": "stock"},
    }
    p.update(extra)
    return p


def personas():
    return [
        base("elevenlabs-design-zimbabwean-man", "elevenlabs", None, "Zimbabwean English", "male",
             kind="design", voice_description="A wise, calm Zimbabwean man in his 50s.", seed=41,
             recommended_for=["atlas"], tags=["elevenlabs", "voice-design"]),
        base("elevenlabs-olufunmilola", "elevenlabs", "9Dbo4hEvXQ5l7MXGZFQA", "Nigerian English", "female",
             recommended_for=["character:hannah"]),
        base("fishaudio-zimbabwe-male", "fishaudio", "7e484c38e51d4de5a5bef5bd7e115572", "Zimbabwean English", "male",
             fish_model="s2.1-pro", recommended_for=["board:leverage_philosopher", "atlas"]),
        base("intron-en-zulu-male", "intron", "en/zulu/male", "Zulu-accented English (South Africa)", "male",
             output_format="wav", intron={"voice_language": "en", "voice_accent": "zulu", "voice_gender": "male"},
             recommended_for=["character:sentinel"]),
        base("piper-en-us-lessac-medium", "piper", "en_US-lessac-medium", "American English", "unspecified",
             output_format="wav", cost_per_1k_chars=0.0, rate=1.0),
        base("azure-en-za-luke", "azure", "en-ZA-LukeNeural", "South African English", "male",
             language="en-ZA", output_format=vp.AZURE_OUTPUT_FORMAT),
    ]


def all_fakes(**overrides):
    fakes = {
        "elevenlabs": FakeDesignRenderer(),
        "fishaudio": FakeRenderer("fishaudio"),
        "intron": FakeRenderer("intron", ext="wav"),
        "piper": FakeRenderer("piper", ext="wav"),
        "azure": FakeRenderer("azure"),
    }
    fakes.update(overrides)
    return fakes


# ─── catalogue ───────────────────────────────────────────────────────────────


def test_catalogue_loads_with_required_fields_unique_slugs_and_no_azure():
    loaded = vp.load_personas(CATALOGUE)
    slugs = [p["slug"] for p in loaded]
    assert len(slugs) == len(set(slugs))
    assert not any(p["provider"] == "azure" for p in loaded), "Azure personas were removed at Jason's request"
    for p in loaded:
        missing = REQUIRED_FIELDS - set(p)
        assert not missing, f"{p['slug']} missing {missing}"
        assert p["provider"] in {"elevenlabs", "fishaudio", "intron", "piper"}
        assert p["consent"] == {"type": "stock"}
        for target in p["recommended_for"]:
            assert target == "atlas" or target.startswith(("board:", "character:")), target
    providers = {p["provider"] for p in loaded}
    assert providers == {"elevenlabs", "fishaudio", "intron", "piper"}


def test_catalogue_covers_the_new_provider_mix():
    by_slug = {p["slug"]: p for p in vp.load_personas(CATALOGUE)}

    designs = [p for p in by_slug.values() if p.get("kind") == "design"]
    assert len(designs) == 4 and all(p["provider"] == "elevenlabs" for p in designs)
    assert all(p["provider_voice_id"] is None and p["voice_description"] for p in designs)
    assert all(p["design_model_id"] == "eleven_multilingual_ttv_v2" for p in designs)
    assert by_slug["elevenlabs-design-zimbabwean-man"]["recommended_for"] == ["atlas"]
    assert "Zimbabwean" in by_slug["elevenlabs-design-zimbabwean-man"]["voice_description"]

    assert by_slug["elevenlabs-nz-nigerian-man"]["provider_voice_id"] == "gsyHQ9kWCDIipR26RqQ1"
    assert by_slug["elevenlabs-olufunmilola"]["provider_voice_id"] == "9Dbo4hEvXQ5l7MXGZFQA"
    el_placeholders = [p for p in by_slug.values() if p["provider"] == "elevenlabs" and p.get("discover")]
    assert {p["discover"]["accent"] for p in el_placeholders} == {"nigerian", "south african", "kenyan", "ghanaian", "zimbabwean"}

    fish = [p for p in by_slug.values() if p["provider"] == "fishaudio" and p["provider_voice_id"]]
    assert 4 <= len(fish) <= 6
    assert all(len(p["provider_voice_id"]) == 32 and p["fish_model"] == "s2.1-pro" for p in fish)
    assert {p["accent"] for p in fish} == {"Zimbabwean English", "Nigerian English", "South African English", "Kenyan English"}

    intron = [p for p in by_slug.values() if p["provider"] == "intron"]
    accents = {p["intron"]["voice_accent"] for p in intron if p["intron"]["voice_language"] == "en"}
    assert accents == {"zulu", "xhosa", "swahili", "yoruba", "igbo", "hausa", "sepedi", "setswana"}
    shona = [p for p in intron if p["intron"]["voice_language"] == "sn"]
    assert len(shona) == 2 and all(p["intron"]["voice_accent"] == "shona" and "experimental" in p["tags"] for p in shona)
    assert all(p["provider_voice_id"] == f"{p['intron']['voice_language']}/{p['intron']['voice_accent']}/{p['intron']['voice_gender']}" for p in intron)

    assert by_slug["piper-en-us-lessac-medium"]["cost_per_1k_chars"] == 0.0
    assert all(p["cost_per_1k_chars"] is None for p in by_slug.values() if p["provider"] != "piper")

    # board seats are spread across providers, and every seat has a primary
    seat_primary = {}
    for p in by_slug.values():
        for target in p["recommended_for"]:
            seat_primary.setdefault(target, p["provider"])
    for seat, _label in vp.SEATS:
        assert seat in seat_primary, f"no persona recommended for {seat}"
    assert {seat_primary[s] for s, _ in vp.SEATS if s.startswith("board:")} >= {"elevenlabs", "fishaudio"}


def test_sample_lines_and_design_text():
    assert tuple(vp.SAMPLE_LINES) == vp.SAMPLE_ORDER == ("greeting", "verdict", "apology")
    for key, line in vp.SAMPLE_LINES.items():
        assert 12 <= len(line.split()) <= 20, (key, len(line.split()))
    assert 100 <= len(vp.DESIGN_TEXT) <= 1000  # ElevenLabs design text constraint
    assert all(line in vp.DESIGN_TEXT for line in vp.SAMPLE_LINES.values())


# ─── SSML (Azure path kept) ──────────────────────────────────────────────────


def test_azure_ssml_with_style_and_without():
    hd = dict(personas()[5], style="reflective", style_degree=1.0, provider_voice_id="en-ZA-Luke:DragonHDOmniNeural")
    ssml = vp.azure_ssml(hd, "Hello & welcome")
    assert 'xml:lang="en-ZA"' in ssml
    assert '<voice name="en-ZA-Luke:DragonHDOmniNeural">' in ssml
    assert '<mstts:express-as style="reflective" styledegree="1.0">' in ssml
    assert "Hello &amp; welcome" in ssml

    standard = dict(personas()[5], rate="-5%", pitch="+2%")
    ssml = vp.azure_ssml(standard, "Plain")
    assert "express-as" not in ssml
    assert '<prosody rate="-5%" pitch="+2%">Plain</prosody>' in ssml


# ─── rendering with fake renderers ───────────────────────────────────────────


def test_render_all_writes_files_manifest_and_skips_missing_providers(tmp_path):
    renderers = all_fakes(fishaudio=FakeRenderer("fishaudio", available=False))
    manifest = vp.render_all(personas(), renderers, tmp_path)

    assert renderers["elevenlabs"].prepared and renderers["intron"].prepared and not renderers["fishaudio"].prepared
    assert (tmp_path / "elevenlabs-olufunmilola" / "greeting.mp3").read_bytes() == b"AUDIO:elevenlabs-olufunmilola:greeting"
    assert (tmp_path / "intron-en-zulu-male" / "verdict.wav").exists()
    assert (tmp_path / "piper-en-us-lessac-medium" / "apology.wav").exists()
    assert not (tmp_path / "fishaudio-zimbabwe-male").exists()

    by_slug = {p["slug"]: p for p in manifest["personas"]}
    assert "azure-en-za-luke" not in by_slug  # disabled by default
    assert manifest["disabled_providers"] == ["azure"]
    assert "azure" not in manifest["providers"]

    fish = by_slug["fishaudio-zimbabwe-male"]
    assert fish["status"] == "skipped" and fish["missing_env"] == "needs FISH_AUDIO_API_KEY"
    assert fish["samples"] == {"greeting": None, "verdict": None, "apology": None}
    assert manifest["providers"]["fishaudio"] == {"label": "Fish Audio", "available": False, "reason": "needs FISH_AUDIO_API_KEY"}
    assert manifest["providers"]["intron"]["available"] is True

    el = by_slug["elevenlabs-olufunmilola"]
    assert el["status"] == "rendered"
    assert el["samples"]["greeting"] == {"file": "elevenlabs-olufunmilola/greeting.mp3", "exists": True,
                                         "bytes": len(b"AUDIO:elevenlabs-olufunmilola:greeting"), "reused": False}
    # 3 library personas x 3 lines + 2 design takes
    assert manifest["summary"] == {"personas": 5, "rendered": 11, "reused": 0, "failed": 0}
    assert manifest["design_text"] == vp.DESIGN_TEXT

    path = vp.write_manifest(tmp_path, manifest)
    assert json.loads(path.read_text(encoding="utf-8"))["summary"]["rendered"] == 11


def test_render_all_includes_azure_only_when_asked(tmp_path):
    with_flag = vp.render_all(personas(), all_fakes(), tmp_path / "a", include_azure=True)
    assert "azure-en-za-luke" in {p["slug"] for p in with_flag["personas"]}
    assert with_flag["providers"]["azure"]["label"] == "Azure Speech (disabled by default)"
    assert with_flag["disabled_providers"] == []

    only_azure = vp.render_all(personas(), all_fakes(), tmp_path / "b", only=["azure"])
    assert [p["slug"] for p in only_azure["personas"]] == ["azure-en-za-luke"]  # --only azure implies include


def test_design_flow_persists_takes_and_is_idempotent(tmp_path):
    renderer = FakeDesignRenderer(takes=3)
    manifest = vp.render_all(personas(), {"elevenlabs": renderer}, tmp_path, only=["elevenlabs"])
    design = next(p for p in manifest["personas"] if p["kind"] == "design")

    assert renderer.design_calls == [("elevenlabs-design-zimbabwean-man", vp.DESIGN_TEXT)]
    assert design["status"] == "rendered" and design["samples"] == {"greeting": None, "verdict": None, "apology": None}
    assert [t["file"] for t in design["takes"]] == [f"elevenlabs-design-zimbabwean-man/take-{i}.mp3" for i in (1, 2, 3)]
    assert [t["generated_voice_id"] for t in design["takes"]] == [f"gen-elevenlabs-design-zimbabwean-man-{i}" for i in (1, 2, 3)]
    assert (tmp_path / "elevenlabs-design-zimbabwean-man" / "take-2.mp3").read_bytes() == b"TAKE2"
    meta = json.loads((tmp_path / "elevenlabs-design-zimbabwean-man" / "design.json").read_text(encoding="utf-8"))
    assert meta["created"] is False and "POST https://api.elevenlabs.io/v1/text-to-voice" in meta["create_with"]
    assert meta["text"] == vp.DESIGN_TEXT and meta["model_id"] == "eleven_multilingual_ttv_v2" and meta["seed"] == 41
    assert manifest["summary"]["rendered"] == 6  # 3 takes + 3 library lines

    strict = FakeDesignRenderer(forbid_calls=True)
    again = vp.render_all(personas(), {"elevenlabs": strict}, tmp_path, only=["elevenlabs"])
    design_again = next(p for p in again["personas"] if p["kind"] == "design")
    assert design_again["status"] == "rendered" and len(design_again["takes"]) == 3
    assert again["summary"] == {"personas": 2, "rendered": 0, "reused": 6, "failed": 0}


def test_design_failure_is_recorded_and_greyed_without_key(tmp_path):
    failing = vp.render_all(personas(), {"elevenlabs": FakeDesignRenderer(fail=True)}, tmp_path / "f", only=["elevenlabs"])
    design = next(p for p in failing["personas"] if p["kind"] == "design")
    assert design["status"] == "failed" and design["errors"] == ["design: simulated design failure"]
    assert failing["summary"]["failed"] == 1

    no_key = vp.render_all(personas(), {"elevenlabs": FakeRenderer("elevenlabs", available=False)}, tmp_path / "n", only=["elevenlabs"])
    design = next(p for p in no_key["personas"] if p["kind"] == "design")
    assert design["status"] == "skipped" and design["missing_env"] == "needs ELEVENLABS_API_KEY" and design["takes"] == []


def test_render_all_is_idempotent_and_never_rerenders_existing_files(tmp_path):
    first = vp.render_all(personas(), {"fishaudio": FakeRenderer("fishaudio")}, tmp_path, only=["fishaudio"])
    assert first["summary"]["rendered"] == 3

    strict = FakeRenderer("fishaudio", forbid_calls=True)
    second = vp.render_all(personas(), {"fishaudio": strict}, tmp_path, only=["fishaudio"])
    assert second["summary"] == {"personas": 1, "rendered": 0, "reused": 3, "failed": 0}
    assert all(s["reused"] for s in second["personas"][0]["samples"].values())


def test_render_all_records_per_sample_errors_without_stopping(tmp_path):
    renderer = FakeRenderer("intron", fail_on={"verdict"}, ext="wav")
    manifest = vp.render_all(personas(), {"intron": renderer}, tmp_path, only=["intron"])
    entry = manifest["personas"][0]
    assert entry["status"] == "partial"
    assert entry["samples"]["greeting"] and entry["samples"]["apology"]
    assert entry["samples"]["verdict"] is None
    assert entry["errors"] == ["verdict: simulated verdict failure"]
    assert manifest["summary"]["failed"] == 1


def test_only_filter_limits_providers(tmp_path):
    manifest = vp.render_all(personas(), {"piper": FakeRenderer("piper", ext="wav")}, tmp_path, only=["piper"])
    assert [p["slug"] for p in manifest["personas"]] == ["piper-en-us-lessac-medium"]
    assert vp._parse_only("fishaudio, intron") == ["fishaudio", "intron"]
    with pytest.raises(SystemExit):
        vp._parse_only("polly")


# ─── page ────────────────────────────────────────────────────────────────────


def test_render_page_groups_cards_greys_missing_providers_and_has_choice_controls(tmp_path):
    renderers = all_fakes(
        fishaudio=FakeRenderer("fishaudio", available=False),
        intron=FakeRenderer("intron", fail_on={"apology"}, ext="wav"),
        piper=FakeRenderer("piper", available=False),
    )
    manifest = vp.render_all(personas(), renderers, tmp_path)
    page = vp.render_page(manifest, TEMPLATE.read_text(encoding="utf-8"))

    assert "<title>Atlas voice options</title>" in page
    assert "__GROUPS__" not in page and "__SEATS__" not in page and "__PROVIDER_STATUS__" not in page

    # provider grouping and status; Azure absent unless --include-azure
    for provider in ("elevenlabs", "fishaudio", "intron", "piper"):
        assert f'<section data-provider="{provider}">' in page
    assert 'data-provider="azure"' not in page and "AZURE_SPEECH_KEY" not in page
    assert "Fish Audio: needs FISH_AUDIO_API_KEY" in page
    assert "Piper (local dev baseline): needs PIPER_URL" in page
    assert "ElevenLabs: ready" in page and "Intron Sahara: ready" in page
    assert page.index('data-provider="elevenlabs"') < page.index('data-provider="fishaudio"') < page.index('data-provider="intron"') < page.index('data-provider="piper"')

    # greyed card: disabled radio, "needs …" instead of players
    fish_card = page.split('data-slug="fishaudio-zimbabwe-male"')[1].split("</article>")[0]
    assert 'class="card disabled"' in page
    assert 'name="atlas" value="fishaudio-zimbabwe-male" disabled' in fish_card
    assert fish_card.count("needs FISH_AUDIO_API_KEY") == 3 and "<audio" not in fish_card

    # rendered library card: two players, one per-sample error, enabled radio
    intron_card = page.split('data-slug="intron-en-zulu-male"')[1].split("</article>")[0]
    assert 'src="intron-en-zulu-male/greeting.wav"' in intron_card and 'src="intron-en-zulu-male/verdict.wav"' in intron_card
    assert "simulated apology failure" in intron_card
    assert 'name="atlas" value="intron-en-zulu-male">' in intron_card
    assert "cost: unverified" in intron_card and "recommended: character:sentinel" in intron_card

    # design card: description, one player + Atlas radio per take, no card-level radio
    design_card = page.split('data-slug="elevenlabs-design-zimbabwean-man"')[1].split("</article>")[0]
    assert "A wise, calm Zimbabwean man in his 50s." in design_card
    assert 'src="elevenlabs-design-zimbabwean-man/take-1.mp3"' in design_card and 'src="elevenlabs-design-zimbabwean-man/take-2.mp3"' in design_card
    assert 'value="elevenlabs-design-zimbabwean-man@gen-elevenlabs-design-zimbabwean-man-1"' in design_card
    assert "voice design — created only when chosen" in design_card
    assert 'value="elevenlabs-design-zimbabwean-man">' not in design_card
    assert "POST /v1/text-to-voice" in design_card

    # seats: one select per seat, primaries preselected, design takes listed per take
    for seat, _label in vp.SEATS:
        assert f'<select data-seat="{seat}">' in page
    sentinel_select = page.split('data-seat="character:sentinel"')[1].split("</select>")[0]
    assert 'value="intron-en-zulu-male" selected' in sentinel_select
    assert "(no sample)" in sentinel_select
    hannah_select = page.split('data-seat="character:hannah"')[1].split("</select>")[0]
    assert 'value="elevenlabs-olufunmilola" selected' in hannah_select
    assert 'value="elevenlabs-design-zimbabwean-man@gen-elevenlabs-design-zimbabwean-man-2"' in hannah_select
    assert "— take 2" in hannah_select
    assert 'id="copy"' in page and "Copy my choices" in page and '"seats"' in page

    # self-contained: no external scripts/styles
    assert "<script src=" not in page and "<link" not in page and "https://fonts" not in page


def test_render_page_escapes_html_in_persona_fields(tmp_path):
    hostile = dict(personas()[4], display_name='<script>alert(1)</script>', tags=["<b>"], accent="A&B")
    manifest = vp.render_all([hostile], {"piper": FakeRenderer("piper", ext="wav")}, tmp_path)
    page = vp.render_page(manifest, TEMPLATE.read_text(encoding="utf-8"))
    assert "<script>alert(1)</script>" not in page
    assert "&lt;script&gt;alert(1)&lt;/script&gt;" in page
    assert "A&amp;B" in page


def test_choice_value():
    entry = {"slug": "x"}
    assert vp.choice_value(entry) == "x"
    assert vp.choice_value(entry, {"generated_voice_id": "g1"}) == "x@g1"
    assert vp.choice_value(entry, {"generated_voice_id": None}) == "x"


# ─── real renderers over MockTransport ───────────────────────────────────────


def test_elevenlabs_design_endpoint_contract(tmp_path):
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/v1/text-to-voice/design"
        assert request.headers["xi-api-key"] == "el-key"
        seen["body"] = json.loads(request.content)
        return httpx.Response(200, json={
            "previews": [
                {"audio_base_64": base64.b64encode(b"MP3-ONE").decode(), "generated_voice_id": "gv1", "media_type": "audio/mpeg", "duration_secs": 14.2, "language": "en"},
                {"audio_base_64": base64.b64encode(b"MP3-TWO").decode(), "generated_voice_id": "gv2", "media_type": "audio/mpeg", "duration_secs": 13.9, "language": "en"},
                {"audio_base_64": "", "generated_voice_id": "gv3", "media_type": "audio/mpeg"},
            ],
            "text": vp.DESIGN_TEXT,
        })

    design_persona = personas()[0]
    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        renderer = vp.ElevenLabsRenderer("el-key", client)
        manifest = vp.render_all([design_persona], {"elevenlabs": renderer}, tmp_path)

    assert seen["body"] == {
        "voice_description": "A wise, calm Zimbabwean man in his 50s.",
        "text": vp.DESIGN_TEXT,
        "model_id": "eleven_multilingual_ttv_v2",
        "output_format": "mp3",
        "seed": 41,
    }
    entry = manifest["personas"][0]
    assert entry["status"] == "rendered"
    assert [t["generated_voice_id"] for t in entry["takes"]] == ["gv1", "gv2"]  # empty preview skipped
    assert (tmp_path / "elevenlabs-design-zimbabwean-man" / "take-1.mp3").read_bytes() == b"MP3-ONE"
    assert entry["takes"][0]["duration_secs"] == 14.2


def test_elevenlabs_design_rejects_short_text_and_http_errors():
    with httpx.Client(transport=httpx.MockTransport(lambda r: httpx.Response(422, json={"detail": "bad"}))) as client:
        renderer = vp.ElevenLabsRenderer("k", client)
        with pytest.raises(vp.RenderError, match="100-1000"):
            renderer.design(personas()[0], "too short")
        with pytest.raises(vp.RenderError, match="HTTP 422"):
            renderer.design(personas()[0], vp.DESIGN_TEXT)
        with pytest.raises(vp.RenderError, match="voice_description"):
            renderer.design(dict(personas()[0], voice_description=""), vp.DESIGN_TEXT)


def test_elevenlabs_discovery_fills_placeholders_from_shared_voices(tmp_path):
    calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append((request.method, request.url.path, dict(request.url.params), request.headers.get("xi-api-key")))
        if request.url.path == "/v1/shared-voices":
            accent = request.url.params.get("accent")
            if accent == "zimbabwean":
                return httpx.Response(200, json={"voices": []})
            if accent == "nigerian":
                return httpx.Response(200, json={"voices": [
                    {"voice_id": "9Dbo4hEvXQ5l7MXGZFQA", "name": "Olufunmilola", "accent": "nigerian", "gender": "female"},
                    {"voice_id": "NEWVOICE01", "name": "Chidi", "accent": "nigerian", "gender": "male", "preview_url": "https://cdn.example/chidi.mp3"},
                ]})
            if request.url.params.get("search") == "zimbabwean":
                return httpx.Response(200, json={"voices": [{"voice_id": "ZIM01", "name": "Tendai", "gender": "male"}]})
            return httpx.Response(200, json={"voices": []})
        if request.url.path.startswith("/v1/text-to-speech/"):
            body = json.loads(request.content)
            assert body["model_id"] == "eleven_multilingual_v2"
            return httpx.Response(200, content=b"ID3mp3bytes", headers={"content-type": "audio/mpeg"})
        return httpx.Response(404)

    known = personas()[1]
    catalogue = [
        known,  # the known Olufunmilola id must not be re-picked
        dict(known, slug="elevenlabs-library-nigerian-1", provider_voice_id=None, display_name="to discover",
             tags=["elevenlabs", "placeholder"], discover={"accent": "nigerian", "language": "en"}),
        dict(known, slug="elevenlabs-library-zimbabwean-1", provider_voice_id=None, display_name="to discover",
             tags=["elevenlabs", "placeholder"], discover={"accent": "zimbabwean", "language": "en"}),
    ]
    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        renderer = vp.ElevenLabsRenderer("el-key", client)
        manifest = vp.render_all(catalogue, {"elevenlabs": renderer}, tmp_path)

    by_slug = {p["slug"]: p for p in manifest["personas"]}
    nigerian = by_slug["elevenlabs-library-nigerian-1"]
    assert nigerian["provider_voice_id"] == "NEWVOICE01"
    assert nigerian["discovered"] is True and "placeholder" not in nigerian["tags"] and "discovered" in nigerian["tags"]
    assert nigerian["display_name"] == "Chidi (library, nigerian)"
    assert nigerian["preview_url"] == "https://cdn.example/chidi.mp3"
    assert nigerian["status"] == "rendered"
    assert by_slug["elevenlabs-library-zimbabwean-1"]["provider_voice_id"] == "ZIM01"  # via search fallback
    assert [c["voice_id"] for c in manifest["discovery"]["elevenlabs"]["nigerian"]] == ["9Dbo4hEvXQ5l7MXGZFQA", "NEWVOICE01"]
    assert all(key == "el-key" for _, path, _, key in calls if path.startswith("/v1/"))
    tts_paths = sorted({path for _, path, _, _ in calls if path.startswith("/v1/text-to-speech/")})
    assert tts_paths == ["/v1/text-to-speech/9Dbo4hEvXQ5l7MXGZFQA", "/v1/text-to-speech/NEWVOICE01", "/v1/text-to-speech/ZIM01"]


def test_fishaudio_renderer_contract_and_discovery(tmp_path):
    seen = {"tts": [], "search": []}

    def handler(request: httpx.Request) -> httpx.Response:
        assert request.headers["authorization"] == "Bearer fish-key"
        if request.url.path == "/v1/tts":
            seen["tts"].append((request.headers.get("model"), json.loads(request.content)))
            return httpx.Response(200, content=b"FISHMP3", headers={"content-type": "audio/mpeg"})
        if request.url.path == "/model":
            seen["search"].append(dict(request.url.params))
            return httpx.Response(200, json={"total": 2, "items": [
                {"_id": "7e484c38e51d4de5a5bef5bd7e115572", "title": "already used", "languages": ["en"], "tags": [], "author": {"nickname": "x"}},
                {"_id": "aa11bb22cc33dd44ee55ff6677889900", "title": "Kwame Ghana", "description": "Ghanaian male", "languages": ["en"],
                 "tags": ["male"], "author": {"nickname": "kwame"}, "like_count": 3, "task_count": 120, "samples": [{"audio": "https://cdn.example/kwame.mp3"}]},
            ]})
        return httpx.Response(404)

    fixed = personas()[2]
    placeholder = dict(fixed, slug="fishaudio-library-ghanaian-1", provider_voice_id=None, display_name="to discover",
                       accent="Ghanaian English", tags=["fishaudio", "placeholder"], discover={"title": "Ghana", "language": "en"})
    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        renderer = vp.FishAudioRenderer("fish-key", client)
        manifest = vp.render_all([fixed, placeholder], {"fishaudio": renderer}, tmp_path)

    assert seen["search"] == [{"title": "Ghana", "page_size": "10", "sort_by": "score", "language": "en"}]
    assert manifest["providers"]["fishaudio"]["model"] == "s2.1-pro"
    model_headers = {m for m, _ in seen["tts"]}
    assert model_headers == {"s2.1-pro"}
    first_body = seen["tts"][0][1]
    assert first_body == {"text": vp.SAMPLE_LINES["greeting"], "reference_id": "7e484c38e51d4de5a5bef5bd7e115572",
                          "format": "mp3", "mp3_bitrate": 128, "latency": "normal", "normalize": True}
    by_slug = {p["slug"]: p for p in manifest["personas"]}
    ghana = by_slug["fishaudio-library-ghanaian-1"]
    assert ghana["provider_voice_id"] == "aa11bb22cc33dd44ee55ff6677889900"  # the already-used id was skipped
    assert ghana["display_name"] == "Kwame Ghana (library, Ghana)" and ghana["preview_url"] == "https://cdn.example/kwame.mp3"
    assert ghana["status"] == "rendered" and by_slug["fishaudio-zimbabwe-male"]["status"] == "rendered"
    assert (tmp_path / "fishaudio-zimbabwe-male" / "verdict.mp3").read_bytes() == b"FISHMP3"
    assert manifest["discovery"]["fishaudio"]["Ghana"][1]["task_count"] == 120


def test_intron_renderer_enqueue_poll_download(tmp_path):
    state = {"polls": 0, "enqueued": []}
    sleeps = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/tts/v1/enqueue":
            assert request.headers["authorization"] == "Bearer intron-key"
            body = json.loads(request.content)
            state["enqueued"].append(body)
            return httpx.Response(200, json={"data": {"text_id": f"job-{len(state['enqueued'])}"}, "message": "tts text queued for processing", "status": "Ok"})
        if request.url.path.startswith("/tts/v1/status/"):
            state["polls"] += 1
            if state["polls"] % 3 == 1:
                return httpx.Response(200, json={"data": {"processing_status": "TTS_TEXT_AUDIO_PROCESSING"}, "status": "Ok"})
            if state["polls"] % 3 == 2:
                return httpx.Response(429, headers={"retry-after": "1"})
            job = request.url.path.rsplit("/", 1)[1]
            return httpx.Response(200, json={"data": {"processing_status": "TTS_TEXT_AUDIO_GENERATED", "audio_path": f"https://cdn.intron.io/audio/{job}.wav", "audio_duration_in_seconds": 6}, "status": "Ok"})
        if request.url.host == "cdn.intron.io":
            assert request.headers.get("authorization") == "Bearer intron-key"  # intron host: auth sent
            return httpx.Response(200, content=b"RIFFintron", headers={"content-type": "audio/wav"})
        return httpx.Response(404)

    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        renderer = vp.IntronRenderer("intron-key", client, poll_interval=0.01, sleep=sleeps.append)
        manifest = vp.render_all([personas()[3]], {"intron": renderer}, tmp_path)

    entry = manifest["personas"][0]
    assert entry["status"] == "rendered"
    assert state["enqueued"][0] == {"text": vp.SAMPLE_LINES["greeting"], "voice_language": "en", "voice_accent": "zulu", "voice_gender": "male", "output_audio_format": "wav"}
    assert len(state["enqueued"]) == 3 and state["polls"] == 9
    assert sleeps.count(1.0) == 3  # honoured Retry-After on each 429
    assert (tmp_path / "intron-en-zulu-male" / "greeting.wav").read_bytes() == b"RIFFintron"


def test_intron_renderer_failure_timeout_and_foreign_audio_host(monkeypatch):
    def failing(request):
        if request.url.path.endswith("/enqueue"):
            return httpx.Response(200, json={"data": {"text_id": "j1"}})
        return httpx.Response(200, json={"data": {"processing_status": "TTS_TEXT_AUDIO_PROCESSING_FAILED", "error": "Invalid accent for language"}})

    with httpx.Client(transport=httpx.MockTransport(failing)) as client:
        renderer = vp.IntronRenderer("k", client, sleep=lambda s: None)
        with pytest.raises(vp.RenderError, match="Invalid accent"):
            renderer.render(personas()[3], "x")

    clock = {"t": 0.0}
    monkeypatch.setattr(vp.time, "monotonic", lambda: clock["t"])

    def never_done(request):
        if request.url.path.endswith("/enqueue"):
            return httpx.Response(200, json={"data": {"text_id": "j2"}})
        clock["t"] += 100.0
        return httpx.Response(200, json={"data": {"processing_status": "TTS_TEXT_AUDIO_QUEUED"}})

    with httpx.Client(transport=httpx.MockTransport(never_done)) as client:
        renderer = vp.IntronRenderer("k", client, poll_timeout=150.0, sleep=lambda s: None)
        with pytest.raises(vp.RenderError, match="timed out"):
            renderer.render(personas()[3], "x")

    def foreign_host(request):
        if request.url.path.endswith("/enqueue"):
            return httpx.Response(200, json={"data": {"text_id": "j3"}})
        if request.url.path.startswith("/tts/v1/status/"):
            return httpx.Response(200, json={"processing_status": "TTS_TEXT_AUDIO_GENERATED", "audio_path": "https://s3.example.com/j3.opus"})
        assert "authorization" not in request.headers  # no key leaks to a non-intron host
        return httpx.Response(200, content=b"OPUS")

    with httpx.Client(transport=httpx.MockTransport(foreign_host)) as client:
        renderer = vp.IntronRenderer("k", client, sleep=lambda s: None)
        audio, ext = renderer.render(dict(personas()[3], output_format="opus"), "x")
    assert (audio, ext) == (b"OPUS", "opus")

    with pytest.raises(vp.RenderError, match="voice_accent"):
        vp.IntronRenderer.voice_of({"provider_voice_id": "broken"})
    assert vp.IntronRenderer.voice_of({"provider_voice_id": "sn/shona/female"}) == ("sn", "shona", "female")


def test_piper_renderer_posts_to_synthesize_and_keeps_wav(tmp_path):
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/synthesize"
        body = json.loads(request.content)
        assert body["voice"] == "en_US-lessac-medium" and body["text"] in vp.SAMPLE_LINES.values()
        return httpx.Response(200, content=b"RIFFwav", headers={"content-type": "audio/wav"})

    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        manifest = vp.render_all([personas()[4]], {"piper": vp.PiperRenderer("http://localhost:5000/", client)}, tmp_path)
    assert manifest["personas"][0]["samples"]["greeting"]["file"] == "piper-en-us-lessac-medium/greeting.wav"


def test_azure_renderer_still_works_when_included(tmp_path):
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/cognitiveservices/voices/list":
            return httpx.Response(200, json=[{"ShortName": "en-ZA-LukeNeural"}])
        if request.url.path == "/cognitiveservices/v1":
            assert request.headers["x-microsoft-outputformat"] == vp.AZURE_OUTPUT_FORMAT
            assert '<voice name="en-ZA-LukeNeural">' in request.content.decode()
            return httpx.Response(200, content=b"mp3", headers={"content-type": "audio/mpeg"})
        return httpx.Response(404)

    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        renderer = vp.AzureRenderer("az-key", "southafricanorth", client)
        assert renderer.disabled_by_default is True
        manifest = vp.render_all([personas()[5]], {"azure": renderer}, tmp_path, include_azure=True)
    entry = manifest["personas"][0]
    assert entry["voice_id_found"] is True and entry["status"] == "rendered"


def test_build_renderers_reads_env_and_reports_missing_keys():
    with httpx.Client(transport=httpx.MockTransport(lambda r: httpx.Response(404))) as client:
        none = vp.build_renderers({}, client)
        assert set(none) == {"elevenlabs", "fishaudio", "intron", "piper", "azure"}
        assert not any(r.available for r in none.values())
        assert none["elevenlabs"].reason == "needs ELEVENLABS_API_KEY"
        assert none["fishaudio"].reason == "needs FISH_AUDIO_API_KEY"
        assert none["intron"].reason == "needs INTRON_API_KEY"
        assert none["piper"].reason == "needs PIPER_URL"
        assert none["azure"].reason == "needs AZURE_SPEECH_KEY + AZURE_SPEECH_REGION"

        full = vp.build_renderers({"ELEVENLABS_API_KEY": "e", "FISH_AUDIO_API_KEY": "f", "INTRON_API_KEY": "i", "PIPER_URL": "http://p",
                                   "AZURE_SPEECH_KEY": "k", "AZURE_SPEECH_REGION": "eastus"}, client)
        assert all(r.available for r in full.values())


# ─── CLI end to end (no keys) ────────────────────────────────────────────────


def test_main_without_keys_writes_greyed_page_and_manifest(tmp_path):
    def handler(request):
        raise AssertionError(f"no network expected, got {request.url}")

    out = tmp_path / "previews"
    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        code = vp.main(["--out", str(out)], environ={}, client=client)
    assert code == 0
    manifest = json.loads((out / "manifest.json").read_text(encoding="utf-8"))
    assert manifest["summary"]["rendered"] == 0 and manifest["summary"]["personas"] >= 30
    assert set(manifest["providers"]) == {"elevenlabs", "fishaudio", "intron", "piper"}
    assert all(not p["available"] for p in manifest["providers"].values())
    assert manifest["disabled_providers"] == ["azure"]
    page = (out / "index.html").read_text(encoding="utf-8")
    for needs in ("needs ELEVENLABS_API_KEY", "needs FISH_AUDIO_API_KEY", "needs INTRON_API_KEY", "needs PIPER_URL"):
        assert needs in page
    assert "AZURE_SPEECH_KEY" not in page
    assert page.count('class="card disabled"') == manifest["summary"]["personas"]
    assert not any(p.is_dir() for p in out.iterdir())  # no empty sample folders for greyed providers


def test_main_include_azure_flag_is_accepted(tmp_path):
    with httpx.Client(transport=httpx.MockTransport(lambda r: httpx.Response(404))) as client:
        code = vp.main(["--out", str(tmp_path / "p"), "--include-azure", "--only", "piper"], environ={}, client=client)
    assert code == 0
    manifest = json.loads((tmp_path / "p" / "manifest.json").read_text(encoding="utf-8"))
    assert manifest["disabled_providers"] == []
