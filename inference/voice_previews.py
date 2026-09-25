"""
SpiderNet OS — Atlas voice options (plan D7 §7, slice 0: the listening file). v2 — no Azure by default.
The provider calls themselves live in tts_providers.py (shared with SpeechService / POST /tts).

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
import datetime as _dt
import html
import json
import logging
import os
import sys
import time  # noqa: F401 — tests patch voice_previews.time.monotonic (the Intron poll clock in tts_providers)
from pathlib import Path
from typing import Mapping, Optional, Sequence

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

PROVIDER_LABELS = {
    "elevenlabs": "ElevenLabs",
    "fishaudio": "Fish Audio",
    "intron": "Intron Sahara",
    "piper": "Piper (local dev baseline)",
    "azure": "Azure Speech (disabled by default)",
}

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

# The provider calls live in tts_providers.py (shared with speech.py / POST /tts); re-exported here so
# `voice_previews.ElevenLabsRenderer`, `voice_previews.RenderError`, … keep working for callers and tests.
from tts_providers import (  # noqa: E402,F401 — after the sys.path bootstrap above
    AZURE_OUTPUT_FORMAT,
    DISABLED_BY_DEFAULT,
    ELEVENLABS_BASE,
    ELEVENLABS_CREATE_ENDPOINT,
    ELEVENLABS_DESIGN_MODEL,
    ELEVENLABS_MODEL,
    ELEVENLABS_OUTPUT_FORMAT,
    FISH_BASE,
    FISH_MODEL,
    INTRON_BASE,
    INTRON_STATUS_FAILED,
    INTRON_STATUS_OK,
    PROVIDER_NEEDS,
    PROVIDER_ORDER,
    AzureRenderer,
    BaseRenderer,
    ElevenLabsRenderer,
    FishAudioRenderer,
    IntronRenderer,
    PiperRenderer,
    RenderError,
    azure_ssml,
    build_renderers,
)

log = logging.getLogger("voice_previews")


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
