"""
SpiderNet OS — inference plane doctor (plan PR 0: "DeepSeek via BytePlus ModelArk verified end to end").

    python -m inference.doctor [--env-file path] [--inference-url http://127.0.0.1:9001] [--json]
    (from inside the inference directory / on the prod host:  python -m doctor ...)

One verdict per line — PASS / WARN / FAIL — and a non-zero exit code if anything FAILs.
Secret values are never printed; only whether a key is set and how long it is.

Checks:
  env:*                    DEEPSEEK_API_KEY, DEEPSEEK_BASE_URL, DEEPSEEK_ARK_MODEL_FLASH/PRO, OPENAI_API_KEY, OLLAMA_*
  modelark:host            DEEPSEEK_BASE_URL is the international ModelArk host (never cn-beijing)
  modelark:chat[flash|pro] live chat-completions round-trip for BOTH model ids (latency, tokens, status, reply)
  router:rank_models       policy_router.rank_models() puts a modelark model first for a default request
  inference:/health, /v1/classify, /generate[flash|pro]   round-trips when --inference-url is given
  tts:providers            which TTS/STT providers are configured (names only)
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import time
from dataclasses import asdict, dataclass, field
from typing import Callable, Mapping, MutableMapping, Optional, Sequence
from urllib.parse import urlparse

import httpx

# The inference plane uses flat imports (`from config import ...`). Make them resolve whether
# this file runs as `python -m inference.doctor` (repo root), `python -m doctor` (inside the
# directory, which is how /opt/spidernet-inference is laid out) or `python inference/doctor.py`.
_HERE = os.path.dirname(os.path.abspath(__file__))
if _HERE not in sys.path:
    sys.path.insert(0, _HERE)

PASS, WARN, FAIL = "PASS", "WARN", "FAIL"

INTERNATIONAL_ARK_HOST = "ark.ap-southeast.bytepluses.com"
CHINA_HOST_MARKERS = ("cn-beijing", "volces.com")
PROBE_PROMPT = "Reply with one word: ready"  # ~5 tokens
PROBE_MAX_TOKENS = 8
REPLY_PREVIEW_CHARS = 60
CLASSIFY_MESSAGE = "What is our cash runway this month?"

SECRET_ENV_NAMES = (
    "DEEPSEEK_API_KEY", "OPENAI_API_KEY", "ELEVENLABS_API_KEY", "AZURE_SPEECH_KEY",
    "DEEPGRAM_API_KEY", "INFERENCE_TOKEN",
)


@dataclass
class Check:
    name: str
    status: str
    detail: str
    data: dict = field(default_factory=dict)

    def line(self) -> str:
        return f"{self.status:<4}  {self.name:<38} {self.detail}"


@dataclass
class Settings:
    """What the code will actually use (read from config after the env file is applied)."""
    base_url: str
    model_flash: str
    model_pro: str
    defaults: Mapping[str, str] = field(default_factory=dict)


# ─── Env handling ────────────────────────────────────────────────────────────


def mask(value: Optional[str]) -> str:
    """Describe a secret without revealing it."""
    value = value or ""
    return f"set ({len(value)} chars)" if value else "missing"


def load_env_file(path: str) -> dict:
    """Parse a dotenv-style file: KEY=value, optional `export`, quotes and # comments."""
    values: dict = {}
    with open(path, "r", encoding="utf-8") as fh:
        for raw in fh:
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            if line.startswith("export "):
                line = line[len("export "):].strip()
            key, _, value = line.partition("=")
            key = key.strip()
            value = value.strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
                value = value[1:-1]
            elif " #" in value:
                value = value.split(" #", 1)[0].rstrip()
            if key:
                values[key] = value
    return values


def apply_env_file(values: Mapping[str, str], environ: MutableMapping[str, str]) -> list:
    """Fill in names the process environment does not already define. Returns the names applied."""
    applied = []
    for key, value in values.items():
        if key not in environ:
            environ[key] = value
            applied.append(key)
    return applied


# ─── Checks ──────────────────────────────────────────────────────────────────


def check_env(env: Mapping[str, str], defaults: Mapping[str, str]) -> list:
    checks = []

    key = env.get("DEEPSEEK_API_KEY", "")
    checks.append(Check(
        "env:DEEPSEEK_API_KEY",
        PASS if key else FAIL,
        mask(key) if key else "missing -- ModelArk cannot be reached without it",
    ))

    for name in ("DEEPSEEK_BASE_URL", "DEEPSEEK_ARK_MODEL_FLASH", "DEEPSEEK_ARK_MODEL_PRO"):
        value = env.get(name, "")
        default = defaults.get(name, "")
        checks.append(Check(
            f"env:{name}",
            PASS if value else WARN,
            value if value else f"not set; code default '{default}' applies",
        ))

    openai = env.get("OPENAI_API_KEY", "")
    checks.append(Check(
        "env:OPENAI_API_KEY",
        PASS if openai else WARN,
        mask(openai) if openai else "missing -- no OpenAI fallback",
    ))

    ollama = {k: env[k] for k in sorted(env) if k.startswith("OLLAMA_")}
    if ollama:
        detail = ", ".join(f"{k}={v}" for k, v in ollama.items())
    else:
        detail = "none set; defaults OLLAMA_URL=http://localhost:11434 OLLAMA_ENABLED=1 apply (routing will try a local Ollama)"
    checks.append(Check("env:OLLAMA_*", PASS if ollama else WARN, detail, {"names": list(ollama)}))
    return checks


def check_modelark_host(base_url: str) -> Check:
    name = "modelark:host"
    url = (base_url or "").strip()
    if not url:
        return Check(name, FAIL, "DEEPSEEK_BASE_URL is empty")
    host = (urlparse(url).hostname or "").lower()
    if any(marker in url.lower() for marker in CHINA_HOST_MARKERS):
        return Check(name, FAIL, f"{url} is a China-region host; production must use the international host https://{INTERNATIONAL_ARK_HOST}/api/v3", {"host": host})
    if host == INTERNATIONAL_ARK_HOST:
        return Check(name, PASS, f"{url} (international ModelArk)", {"host": host})
    return Check(name, WARN, f"{url} is not the known international host {INTERNATIONAL_ARK_HOST}", {"host": host})


def probe_modelark(client: httpx.Client, base_url: str, api_key: str, model_id: str, label: str) -> Check:
    """Live chat-completions round-trip with a ~5-token prompt. Reports latency, usage, status, reply."""
    name = f"modelark:chat[{label}]"
    if not api_key:
        return Check(name, WARN, "skipped: DEEPSEEK_API_KEY missing", {"model": model_id})
    if not model_id:
        return Check(name, FAIL, f"no model id configured for {label}")

    url = base_url.rstrip("/") + "/chat/completions"
    started = time.perf_counter()
    try:
        resp = client.post(
            url,
            headers={"Authorization": f"Bearer {api_key}"},
            json={
                "model": model_id,
                "messages": [{"role": "user", "content": PROBE_PROMPT}],
                "max_tokens": PROBE_MAX_TOKENS,
                "temperature": 0,
            },
            timeout=45.0,
        )
    except httpx.HTTPError as exc:
        return Check(name, FAIL, f"model={model_id} request failed: {type(exc).__name__}: {str(exc)[:120]}", {"model": model_id})

    latency_ms = round((time.perf_counter() - started) * 1000)
    data = {"model": model_id, "http_status": resp.status_code, "latency_ms": latency_ms}
    if resp.status_code != 200:
        return Check(name, FAIL, f"model={model_id} HTTP {resp.status_code} in {latency_ms} ms: {resp.text[:120]!r}", data)

    try:
        body = resp.json()
        text = body["choices"][0]["message"]["content"]
        usage = body.get("usage") or {}
    except Exception as exc:  # noqa: BLE001
        return Check(name, FAIL, f"model={model_id} HTTP 200 but unexpected body: {exc}", data)

    preview = " ".join(str(text or "").split())[:REPLY_PREVIEW_CHARS]
    data.update({
        "prompt_tokens": usage.get("prompt_tokens"),
        "completion_tokens": usage.get("completion_tokens"),
        "total_tokens": usage.get("total_tokens"),
        "reply_preview": preview,
        "served_model": body.get("model"),
    })
    detail = (
        f"model={model_id} HTTP 200 {latency_ms} ms tokens={usage.get('total_tokens', '?')} "
        f"(prompt {usage.get('prompt_tokens', '?')}, completion {usage.get('completion_tokens', '?')}) reply={preview!r}"
    )
    if not preview:
        return Check(name, WARN, detail + " -- empty reply", data)
    return Check(name, PASS, detail, data)


def check_ranking(rank_models_fn: Callable[..., Sequence[str]], provider_of: Callable[[str], str],
                  key_present: bool) -> Check:
    """rank_models() for a default request must put a modelark model first, with OpenAI/Ollama behind it."""
    name = "router:rank_models"
    if not key_present:
        return Check(name, WARN, "skipped: DEEPSEEK_API_KEY missing, so modelark models are filtered out of routing")
    try:
        ranked = list(rank_models_fn(cost_ceiling=50.0, latency_max=None, tenant_tier="starter"))
    except Exception as exc:  # noqa: BLE001
        return Check(name, FAIL, f"rank_models() raised {type(exc).__name__}: {exc}")
    if not ranked:
        return Check(name, FAIL, "rank_models() returned no candidates")

    providers = [provider_of(m) for m in ranked]
    order = ", ".join(f"{m}({p})" for m, p in list(zip(ranked, providers))[:6])
    fallbacks = sorted({p for p in providers[1:] if p != "modelark"})
    data = {"order": ranked, "providers": providers, "fallbacks": fallbacks}

    if providers[0] == "modelark":
        if fallbacks:
            return Check(name, PASS, f"first={ranked[0]}; fallbacks={'/'.join(fallbacks)}; order: {order}", data)
        return Check(name, WARN, f"first={ranked[0]} but no OpenAI/Ollama fallback is available; order: {order}", data)

    return Check(
        name, FAIL,
        f"first={ranked[0]} ({providers[0]}) outranks modelark -- rank_models sorts by cost_per_1k_tokens, "
        f"so a cheaper OpenAI/Ollama row wins whenever its key/host is configured; order: {order}",
        data,
    )


def _service_headers(token: Optional[str]) -> dict:
    headers = {"Accept": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    return headers


def check_inference_health(client: httpx.Client, base_url: str, token: Optional[str] = None) -> Check:
    name = "inference:/health"
    url = base_url.rstrip("/") + "/health"
    started = time.perf_counter()
    try:
        resp = client.get(url, headers=_service_headers(token), timeout=15.0)
    except httpx.HTTPError as exc:
        return Check(name, FAIL, f"{url} unreachable: {type(exc).__name__}: {str(exc)[:120]}")
    latency_ms = round((time.perf_counter() - started) * 1000)
    if resp.status_code != 200:
        return Check(name, FAIL, f"HTTP {resp.status_code} in {latency_ms} ms: {resp.text[:120]!r}")
    try:
        body = resp.json()
    except ValueError:
        return Check(name, FAIL, f"HTTP 200 but not JSON: {resp.text[:120]!r}")
    status = str(body.get("status", ""))
    detail = f"HTTP 200 {latency_ms} ms status={status} plane={body.get('plane')} version={body.get('version')}"
    return Check(name, PASS if status == "ok" else FAIL, detail, {"latency_ms": latency_ms, **body})


def check_inference_classify(client: httpx.Client, base_url: str, model: str, token: Optional[str] = None) -> Check:
    name = "inference:/v1/classify"
    url = base_url.rstrip("/") + "/v1/classify"
    started = time.perf_counter()
    try:
        resp = client.post(
            url, headers=_service_headers(token),
            json={"message": CLASSIFY_MESSAGE, "model": model}, timeout=90.0,
        )
    except httpx.HTTPError as exc:
        return Check(name, FAIL, f"{url} unreachable: {type(exc).__name__}: {str(exc)[:120]}")
    latency_ms = round((time.perf_counter() - started) * 1000)
    if resp.status_code != 200:
        return Check(name, FAIL, f"model={model} HTTP {resp.status_code} in {latency_ms} ms: {resp.text[:160]!r}")
    try:
        body = resp.json()
        intent = body["intent"]
        confidence = body.get("confidence")
    except Exception as exc:  # noqa: BLE001
        return Check(name, FAIL, f"HTTP 200 but unexpected body: {exc}")
    return Check(
        name, PASS,
        f"model={model} HTTP 200 {latency_ms} ms intent={intent} confidence={confidence}",
        {"latency_ms": latency_ms, "intent": intent, "confidence": confidence},
    )


def check_inference_generate(client: httpx.Client, base_url: str, model: str, label: str,
                             token: Optional[str] = None) -> Check:
    name = f"inference:/generate[{label}]"
    url = base_url.rstrip("/") + "/generate"
    started = time.perf_counter()
    try:
        resp = client.post(
            url, headers=_service_headers(token),
            json={"prompt": PROBE_PROMPT, "model": model, "max_tokens": PROBE_MAX_TOKENS, "temperature": 0.0},
            timeout=90.0,
        )
    except httpx.HTTPError as exc:
        return Check(name, FAIL, f"{url} unreachable: {type(exc).__name__}: {str(exc)[:120]}")
    latency_ms = round((time.perf_counter() - started) * 1000)
    if resp.status_code != 200:
        return Check(name, FAIL, f"model={model} HTTP {resp.status_code} in {latency_ms} ms: {resp.text[:160]!r}")
    try:
        body = resp.json()
        preview = " ".join(str(body.get("text", "")).split())[:REPLY_PREVIEW_CHARS]
    except ValueError:
        return Check(name, FAIL, f"HTTP 200 but not JSON: {resp.text[:120]!r}")
    served = body.get("model")
    provider = body.get("provider")
    detail = (
        f"model={model} HTTP 200 {latency_ms} ms served_by={served}/{provider} "
        f"tokens={body.get('tokens_used')} cost={body.get('cost')} reply={preview!r}"
    )
    status = PASS if provider == "modelark" and served == model else WARN
    if status == WARN:
        detail += " -- served by a fallback, not the requested modelark model"
    return Check(name, status, detail, {"latency_ms": latency_ms, "served_model": served, "provider": provider})


def check_tts(env: Mapping[str, str]) -> Check:
    """Names only. Which speech providers have credentials configured."""
    configured = []
    if env.get("AZURE_SPEECH_KEY") and env.get("AZURE_SPEECH_REGION"):
        configured.append("azure")
    if env.get("ELEVENLABS_API_KEY"):
        configured.append("elevenlabs")
    if env.get("PIPER_URL"):
        configured.append("piper")
    stt = []
    if env.get("DEEPGRAM_API_KEY"):
        stt.append("deepgram")
    if env.get("WHISPER_URL"):
        stt.append("whisper")
    default_tts = env.get("VOICE_TTS_PROVIDER", "piper")
    detail = (
        f"tts={'/'.join(configured) or 'none'} stt={'/'.join(stt) or 'none'} "
        f"default VOICE_TTS_PROVIDER={default_tts}"
    )
    cloud = [p for p in configured if p != "piper"]
    if cloud:
        return Check("tts:providers", PASS, detail, {"tts": configured, "stt": stt})
    return Check("tts:providers", WARN, detail + " -- no cloud TTS key; Atlas voice previews will be greyed out", {"tts": configured, "stt": stt})


# ─── Orchestration ───────────────────────────────────────────────────────────


def run(env: Mapping[str, str], settings: Settings, client: httpx.Client,
        rank_models_fn: Callable[..., Sequence[str]], provider_of: Callable[[str], str],
        inference_url: Optional[str] = None) -> list:
    api_key = env.get("DEEPSEEK_API_KEY", "")
    checks = check_env(env, settings.defaults)
    checks.append(check_modelark_host(settings.base_url))
    checks.append(probe_modelark(client, settings.base_url, api_key, settings.model_flash, "flash"))
    checks.append(probe_modelark(client, settings.base_url, api_key, settings.model_pro, "pro"))
    checks.append(check_ranking(rank_models_fn, provider_of, bool(api_key)))

    if inference_url:
        token = env.get("INFERENCE_TOKEN") or None
        checks.append(check_inference_health(client, inference_url, token))
        checks.append(check_inference_classify(client, inference_url, "deepseek-v4-flash", token))
        checks.append(check_inference_generate(client, inference_url, "deepseek-v4-flash", "flash", token))
        checks.append(check_inference_generate(client, inference_url, "deepseek-v4-pro", "pro", token))
    else:
        checks.append(Check("inference:service", WARN, "skipped: pass --inference-url http://127.0.0.1:9001 to round-trip /health, /v1/classify and /generate"))

    checks.append(check_tts(env))
    return checks


def summarize(checks: Sequence[Check]) -> dict:
    counts = {PASS: 0, WARN: 0, FAIL: 0}
    for check in checks:
        counts[check.status] = counts.get(check.status, 0) + 1
    return {"ok": counts[FAIL] == 0, "pass": counts[PASS], "warn": counts[WARN], "fail": counts[FAIL]}


def assert_no_secrets(text: str, env: Mapping[str, str]) -> None:
    """Defensive: refuse to print output that contains a configured secret value."""
    for name in SECRET_ENV_NAMES:
        value = env.get(name, "")
        if value and len(value) >= 8 and value in text:
            raise RuntimeError(f"refusing to print output containing the value of {name}")


def _load_settings() -> Settings:
    import config as cfg  # noqa: WPS433 — imported after the env file is applied on purpose

    return Settings(
        base_url=cfg.DEEPSEEK_BASE_URL,
        model_flash=cfg.DEEPSEEK_ARK_MODEL_FLASH,
        model_pro=cfg.DEEPSEEK_ARK_MODEL_PRO,
        defaults={
            "DEEPSEEK_BASE_URL": "https://ark.ap-southeast.bytepluses.com/api/v3",
            "DEEPSEEK_ARK_MODEL_FLASH": "deepseek-v4-flash",
            "DEEPSEEK_ARK_MODEL_PRO": "deepseek-v4-pro",
        },
    )


def _load_router():
    import policy_router  # noqa: WPS433
    from config import MODEL_COST_TABLE  # noqa: WPS433

    def provider_of(model: str) -> str:
        return MODEL_COST_TABLE.get(model, {}).get("provider", "?")

    return policy_router.rank_models, provider_of


def main(argv: Optional[Sequence[str]] = None, client: Optional[httpx.Client] = None,
         environ: Optional[MutableMapping[str, str]] = None) -> int:
    parser = argparse.ArgumentParser(prog="doctor", description="SpiderNet inference plane doctor")
    parser.add_argument("--env-file", default=None, help=".env to read (fills names the process env lacks; values never printed)")
    parser.add_argument("--inference-url", default=None, help="running inference service, e.g. http://127.0.0.1:9001")
    parser.add_argument("--json", action="store_true", help="print a JSON report instead of verdict lines")
    args = parser.parse_args(argv)

    env = environ if environ is not None else os.environ
    applied = []
    if args.env_file:
        try:
            applied = apply_env_file(load_env_file(args.env_file), env)
        except OSError as exc:
            print(f"FAIL  env-file  {args.env_file}: {exc}")
            return 1

    settings = _load_settings()
    rank_models_fn, provider_of = _load_router()

    own_client = client is None
    client = client or httpx.Client()
    try:
        checks = run(env, settings, client, rank_models_fn, provider_of, args.inference_url)
    finally:
        if own_client:
            client.close()

    summary = summarize(checks)
    if args.json:
        report = {
            "ok": summary["ok"],
            "summary": summary,
            "env_file": args.env_file,
            "env_file_applied": applied,
            "inference_url": args.inference_url,
            "checks": [asdict(c) for c in checks],
        }
        text = json.dumps(report, indent=2)
    else:
        lines = [c.line() for c in checks]
        if applied:
            lines.insert(0, f"info  env-file  {args.env_file}: applied {len(applied)} name(s) not already in the environment")
        verdict = "OK" if summary["ok"] else "FAIL"
        lines.append(f"---   {verdict}: {summary['pass']} pass, {summary['warn']} warn, {summary['fail']} fail")
        text = "\n".join(lines)

    assert_no_secrets(text, env)
    print(text)
    return 0 if summary["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
