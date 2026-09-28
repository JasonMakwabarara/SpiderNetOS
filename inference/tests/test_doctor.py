"""
Unit tests for inference/doctor.py. httpx is mocked with MockTransport — no network, no server.
"""
import json

import httpx
import pytest

import doctor
from doctor import (
    FAIL,
    PASS,
    WARN,
    Settings,
    apply_env_file,
    check_env,
    check_inference_classify,
    check_inference_generate,
    check_inference_health,
    check_modelark_host,
    check_ranking,
    check_tts,
    load_env_file,
    mask,
    probe_modelark,
    run,
    summarize,
)

SECRET = "sk-modelark-THIS-MUST-NEVER-BE-PRINTED-0123456789"
BASE_URL = "https://ark.ap-southeast.bytepluses.com/api/v3"
DEFAULTS = {
    "DEEPSEEK_BASE_URL": BASE_URL,
    "DEEPSEEK_ARK_MODEL_FLASH": "deepseek-v4-flash",
    "DEEPSEEK_ARK_MODEL_PRO": "deepseek-v4-pro",
}
PROVIDERS = {
    "deepseek-v4-flash": "modelark",
    "deepseek-v4-pro": "modelark",
    "gpt-5-nano": "openai",
    "gemma2:2b": "ollama",
}


def provider_of(model):
    return PROVIDERS.get(model, "?")


def settings():
    return Settings(base_url=BASE_URL, model_flash="deepseek-v4-flash", model_pro="deepseek-v4-pro", defaults=DEFAULTS)


def mock_client(handler):
    return httpx.Client(transport=httpx.MockTransport(handler))


def chat_completion(model, text="ready", prompt_tokens=7, completion_tokens=1):
    return {
        "id": "chatcmpl-test",
        "model": model,
        "choices": [{"index": 0, "message": {"role": "assistant", "content": text}, "finish_reason": "stop"}],
        "usage": {
            "prompt_tokens": prompt_tokens,
            "completion_tokens": completion_tokens,
            "total_tokens": prompt_tokens + completion_tokens,
        },
    }


# ─── env file ────────────────────────────────────────────────────────────────


def test_load_env_file_parses_comments_quotes_and_export(tmp_path):
    env_file = tmp_path / ".env"
    env_file.write_text(
        "# comment\n"
        "DEEPSEEK_API_KEY='abc'\n"
        'DEEPSEEK_BASE_URL="https://x/api/v3"\n'
        "export OLLAMA_ENABLED=0\n"
        "PLAIN=value # trailing comment\n"
        "BROKEN LINE WITHOUT EQUALS\n"
        "\n",
        encoding="utf-8",
    )
    values = load_env_file(str(env_file))
    assert values == {
        "DEEPSEEK_API_KEY": "abc",
        "DEEPSEEK_BASE_URL": "https://x/api/v3",
        "OLLAMA_ENABLED": "0",
        "PLAIN": "value",
    }


def test_apply_env_file_never_overrides_existing_process_env():
    environ = {"DEEPSEEK_API_KEY": "from-process"}
    applied = apply_env_file({"DEEPSEEK_API_KEY": "from-file", "OLLAMA_URL": "http://o:11434"}, environ)
    assert applied == ["OLLAMA_URL"]
    assert environ["DEEPSEEK_API_KEY"] == "from-process"
    assert environ["OLLAMA_URL"] == "http://o:11434"


def test_mask_never_reveals_the_value():
    assert mask(SECRET) == f"set ({len(SECRET)} chars)"
    assert SECRET not in mask(SECRET)
    assert mask("") == "missing"
    assert mask(None) == "missing"


# ─── env checks ──────────────────────────────────────────────────────────────


def test_check_env_missing_key_fails_and_defaults_are_warned():
    checks = {c.name: c for c in check_env({}, DEFAULTS)}
    assert checks["env:DEEPSEEK_API_KEY"].status == FAIL
    assert checks["env:DEEPSEEK_BASE_URL"].status == WARN
    assert "deepseek-v4-flash" in checks["env:DEEPSEEK_ARK_MODEL_FLASH"].detail
    assert checks["env:OPENAI_API_KEY"].status == WARN
    assert checks["env:OLLAMA_*"].status == WARN


def test_check_env_present_key_passes_without_printing_it():
    env = {
        "DEEPSEEK_API_KEY": SECRET,
        "DEEPSEEK_BASE_URL": BASE_URL,
        "DEEPSEEK_ARK_MODEL_FLASH": "ep-flash-123",
        "DEEPSEEK_ARK_MODEL_PRO": "ep-pro-456",
        "OPENAI_API_KEY": "sk-openai-secret-value-xyz",
        "OLLAMA_ENABLED": "0",
        "OLLAMA_URL": "http://localhost:11434",
    }
    checks = {c.name: c for c in check_env(env, DEFAULTS)}
    assert all(c.status == PASS for c in checks.values()), [(c.name, c.status) for c in checks.values()]
    dumped = json.dumps([c.__dict__ for c in checks.values()])
    assert SECRET not in dumped
    assert "sk-openai-secret-value-xyz" not in dumped
    assert "ep-flash-123" in checks["env:DEEPSEEK_ARK_MODEL_FLASH"].detail  # model ids are not secrets
    assert "OLLAMA_ENABLED=0" in checks["env:OLLAMA_*"].detail


# ─── ModelArk host ───────────────────────────────────────────────────────────


def test_modelark_host_international_passes():
    assert check_modelark_host(BASE_URL).status == PASS


@pytest.mark.parametrize("url", [
    "https://ark.cn-beijing.volces.com/api/v3",
    "https://ark.cn-beijing.bytepluses.com/api/v3",
])
def test_modelark_host_china_region_fails(url):
    check = check_modelark_host(url)
    assert check.status == FAIL
    assert "international" in check.detail


def test_modelark_host_unknown_warns_and_empty_fails():
    assert check_modelark_host("https://example.com/api/v3").status == WARN
    assert check_modelark_host("").status == FAIL


# ─── live probe (mocked) ─────────────────────────────────────────────────────


def test_probe_modelark_success_reports_latency_tokens_status_and_preview():
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers.get("authorization")
        seen["body"] = json.loads(request.content)
        return httpx.Response(200, json=chat_completion("ep-flash-123", "ready. " + "x" * 200))

    with mock_client(handler) as client:
        check = probe_modelark(client, BASE_URL, SECRET, "ep-flash-123", "flash")

    assert seen["url"] == BASE_URL + "/chat/completions"
    assert seen["auth"] == f"Bearer {SECRET}"
    assert seen["body"]["model"] == "ep-flash-123"
    assert seen["body"]["max_tokens"] == doctor.PROBE_MAX_TOKENS
    assert seen["body"]["messages"] == [{"role": "user", "content": doctor.PROBE_PROMPT}]

    assert check.status == PASS
    assert check.name == "modelark:chat[flash]"
    assert "HTTP 200" in check.detail and "tokens=8" in check.detail and " ms" in check.detail
    assert check.data["http_status"] == 200
    assert check.data["total_tokens"] == 8
    assert len(check.data["reply_preview"]) == doctor.REPLY_PREVIEW_CHARS
    assert SECRET not in check.detail and SECRET not in json.dumps(check.data)


def test_probe_modelark_http_error_fails_with_status():
    def handler(request):
        return httpx.Response(401, json={"error": {"message": "invalid api key"}})

    with mock_client(handler) as client:
        check = probe_modelark(client, BASE_URL, SECRET, "ep-pro-456", "pro")
    assert check.status == FAIL
    assert "HTTP 401" in check.detail
    assert check.data["http_status"] == 401


def test_probe_modelark_connection_error_fails():
    def handler(request):
        raise httpx.ConnectError("connection refused", request=request)

    with mock_client(handler) as client:
        check = probe_modelark(client, BASE_URL, SECRET, "ep-flash-123", "flash")
    assert check.status == FAIL
    assert "ConnectError" in check.detail


def test_probe_modelark_without_key_is_warn_not_fail():
    def handler(request):  # must never be called
        raise AssertionError("no request expected without a key")

    with mock_client(handler) as client:
        check = probe_modelark(client, BASE_URL, "", "ep-flash-123", "flash")
    assert check.status == WARN
    assert "skipped" in check.detail


def test_probe_modelark_empty_reply_is_warn():
    with mock_client(lambda r: httpx.Response(200, json=chat_completion("m", ""))) as client:
        check = probe_modelark(client, BASE_URL, SECRET, "m", "flash")
    assert check.status == WARN


# ─── ranking ─────────────────────────────────────────────────────────────────


def test_ranking_passes_when_modelark_first_with_fallbacks():
    check = check_ranking(lambda **kw: ["deepseek-v4-flash", "deepseek-v4-pro", "gpt-5-nano", "gemma2:2b"], provider_of, True)
    assert check.status == PASS
    assert check.data["fallbacks"] == ["ollama", "openai"]


def test_ranking_warns_when_modelark_first_but_no_fallbacks():
    check = check_ranking(lambda **kw: ["deepseek-v4-flash", "deepseek-v4-pro"], provider_of, True)
    assert check.status == WARN


def test_ranking_fails_when_another_provider_outranks_modelark():
    check = check_ranking(lambda **kw: ["gpt-5-nano", "deepseek-v4-flash"], provider_of, True)
    assert check.status == FAIL
    assert "gpt-5-nano" in check.detail and "cost" in check.detail


def test_ranking_skipped_without_key_and_fails_on_exception():
    assert check_ranking(lambda **kw: [], provider_of, False).status == WARN

    def boom(**kw):
        raise RuntimeError("table broken")

    assert check_ranking(boom, provider_of, True).status == FAIL
    assert check_ranking(lambda **kw: [], provider_of, True).status == FAIL


def test_ranking_against_real_policy_router_with_only_modelark_key(monkeypatch):
    import policy_router

    monkeypatch.setattr(policy_router, "DEEPSEEK_API_KEY", "x")
    monkeypatch.setattr(policy_router, "OPENAI_API_KEY", "")
    monkeypatch.setattr(policy_router, "OLLAMA_ENABLED", False)
    _, real_provider_of = doctor._load_router()
    check = check_ranking(policy_router.rank_models, real_provider_of, True)
    # Only modelark models survive the filters, so modelark is first but nothing can fall back.
    assert check.status == WARN
    assert check.data["order"][0] == "deepseek-v4-flash"


def test_ranking_prefers_modelark_over_cheaper_openai_when_both_keys_present(monkeypatch):
    """rank_models applies a provider tier (ollama → modelark → openai) before
    the cost sort, so DeepSeek via ModelArk is primary even though gpt-5-nano
    is cheaper in MODEL_COST_TABLE. The doctor PASSes and reports the order."""
    import policy_router

    monkeypatch.setattr(policy_router, "DEEPSEEK_API_KEY", "x")
    monkeypatch.setattr(policy_router, "OPENAI_API_KEY", "y")
    monkeypatch.setattr(policy_router, "OLLAMA_ENABLED", False)
    _, real_provider_of = doctor._load_router()
    check = check_ranking(policy_router.rank_models, real_provider_of, True)
    assert check.status == PASS
    assert check.data["order"][0] == "deepseek-v4-flash"


# ─── inference service (mocked) ──────────────────────────────────────────────


def test_inference_health_pass_and_fail():
    with mock_client(lambda r: httpx.Response(200, json={"status": "ok", "plane": "inference", "version": "3.2.0"})) as c:
        check = check_inference_health(c, "http://127.0.0.1:9001/")
    assert check.status == PASS and "version=3.2.0" in check.detail

    with mock_client(lambda r: httpx.Response(503, text="down")) as c:
        assert check_inference_health(c, "http://127.0.0.1:9001").status == FAIL

    def refused(request):
        raise httpx.ConnectError("refused", request=request)

    with mock_client(refused) as c:
        check = check_inference_health(c, "http://127.0.0.1:9001")
    assert check.status == FAIL and "unreachable" in check.detail


def test_inference_health_sends_bearer_token_when_configured():
    seen = {}

    def handler(request):
        seen["auth"] = request.headers.get("authorization")
        return httpx.Response(200, json={"status": "ok"})

    with mock_client(handler) as c:
        check_inference_health(c, "http://127.0.0.1:9001", token="tok-123")
    assert seen["auth"] == "Bearer tok-123"


def test_inference_classify_round_trip():
    seen = {}

    def handler(request):
        seen["path"] = request.url.path
        seen["body"] = json.loads(request.content)
        return httpx.Response(200, json={"intent": "query_status", "entities": {}, "confidence": 0.9})

    with mock_client(handler) as c:
        check = check_inference_classify(c, "http://127.0.0.1:9001", "deepseek-v4-flash")
    assert seen["path"] == "/v1/classify"
    assert seen["body"] == {"message": doctor.CLASSIFY_MESSAGE, "model": "deepseek-v4-flash"}
    assert check.status == PASS and "intent=query_status" in check.detail

    with mock_client(lambda r: httpx.Response(500, json={"detail": "classify_failed: boom"})) as c:
        assert check_inference_classify(c, "http://127.0.0.1:9001", "deepseek-v4-flash").status == FAIL


def test_inference_generate_pass_when_served_by_modelark_and_warn_on_fallback():
    served_by_modelark = {"text": "ready", "model": "deepseek-v4-pro", "tokens_used": 8, "cost": 0.0000128, "latency_ms": 900.0, "provider": "modelark"}
    with mock_client(lambda r: httpx.Response(200, json=served_by_modelark)) as c:
        check = check_inference_generate(c, "http://127.0.0.1:9001", "deepseek-v4-pro", "pro")
    assert check.status == PASS and check.name == "inference:/generate[pro]"

    fallback = dict(served_by_modelark, model="gpt-5-nano", provider="openai")
    with mock_client(lambda r: httpx.Response(200, json=fallback)) as c:
        check = check_inference_generate(c, "http://127.0.0.1:9001", "deepseek-v4-pro", "pro")
    assert check.status == WARN and "fallback" in check.detail


# ─── tts ─────────────────────────────────────────────────────────────────────


def test_tts_names_only():
    check = check_tts({"ELEVENLABS_API_KEY": "el-secret-value-123456", "FISH_AUDIO_API_KEY": "fish-secret", "INTRON_API_KEY": "intron-secret",
                       "AZURE_SPEECH_KEY": "az", "AZURE_SPEECH_REGION": "southafricanorth", "DEEPGRAM_API_KEY": "dg"})
    assert check.status == PASS
    assert "tts=elevenlabs/fishaudio/intron/azure" in check.detail and "deepgram" in check.detail
    assert "el-secret-value-123456" not in check.detail and "fish-secret" not in check.detail and "intron-secret" not in check.detail
    assert check_tts({"FISH_AUDIO_API_KEY": "f"}).status == PASS
    assert check_tts({"INTRON_API_KEY": "i"}).status == PASS

    assert check_tts({}).status == WARN
    assert check_tts({"PIPER_URL": "http://localhost:5000"}).status == WARN


# ─── run / summary / main ────────────────────────────────────────────────────


def test_run_end_to_end_json_contains_no_secret_and_exit_code_tracks_fail():
    def handler(request: httpx.Request) -> httpx.Response:
        path = request.url.path
        if path.endswith("/chat/completions"):
            return httpx.Response(200, json=chat_completion(json.loads(request.content)["model"]))
        if path == "/health":
            return httpx.Response(200, json={"status": "ok", "plane": "inference", "version": "3.2.0"})
        if path == "/v1/classify":
            return httpx.Response(200, json={"intent": "chat", "entities": {}, "confidence": 0.5})
        if path == "/generate":
            model = json.loads(request.content)["model"]
            return httpx.Response(200, json={"text": "ready", "model": model, "tokens_used": 8, "cost": 0.0, "latency_ms": 1.0, "provider": "modelark"})
        return httpx.Response(404)

    env = {
        "DEEPSEEK_API_KEY": SECRET,
        "DEEPSEEK_BASE_URL": BASE_URL,
        "DEEPSEEK_ARK_MODEL_FLASH": "deepseek-v4-flash",
        "DEEPSEEK_ARK_MODEL_PRO": "deepseek-v4-pro",
        "OPENAI_API_KEY": "sk-openai-secret-value-xyz",
        "OLLAMA_ENABLED": "0",
        "ELEVENLABS_API_KEY": "el-secret",
    }
    ranking = lambda **kw: ["deepseek-v4-flash", "deepseek-v4-pro", "gpt-5-nano"]  # noqa: E731
    with mock_client(handler) as client:
        checks = run(env, settings(), client, ranking, provider_of, "http://127.0.0.1:9001")

    names = [c.name for c in checks]
    assert names == [
        "env:DEEPSEEK_API_KEY", "env:DEEPSEEK_BASE_URL", "env:DEEPSEEK_ARK_MODEL_FLASH", "env:DEEPSEEK_ARK_MODEL_PRO",
        "env:OPENAI_API_KEY", "env:OLLAMA_*", "modelark:host", "modelark:chat[flash]", "modelark:chat[pro]",
        "router:rank_models", "inference:/health", "inference:/v1/classify", "inference:/generate[flash]",
        "inference:/generate[pro]", "tts:providers",
    ]
    assert all(c.status == PASS for c in checks), [(c.name, c.status, c.detail) for c in checks if c.status != PASS]
    summary = summarize(checks)
    assert summary == {"ok": True, "pass": 15, "warn": 0, "fail": 0}

    dumped = json.dumps([c.__dict__ for c in checks]) + "\n".join(c.line() for c in checks)
    assert SECRET not in dumped and "sk-openai-secret-value-xyz" not in dumped and "el-secret" not in dumped
    doctor.assert_no_secrets(dumped, env)  # does not raise


def test_run_without_key_or_service_is_fail_and_never_calls_network():
    def handler(request):
        raise AssertionError(f"unexpected request to {request.url}")

    with mock_client(handler) as client:
        checks = run({}, settings(), client, lambda **kw: [], provider_of, None)
    by_name = {c.name: c for c in checks}
    assert by_name["env:DEEPSEEK_API_KEY"].status == FAIL
    assert by_name["modelark:chat[flash]"].status == WARN
    assert by_name["router:rank_models"].status == WARN
    assert by_name["inference:service"].status == WARN
    assert summarize(checks)["ok"] is False


def test_assert_no_secrets_raises_when_a_secret_would_be_printed():
    with pytest.raises(RuntimeError):
        doctor.assert_no_secrets(f"oops {SECRET}", {"DEEPSEEK_API_KEY": SECRET})


def test_main_uses_env_file_and_emits_json(tmp_path, capsys, monkeypatch):
    env_file = tmp_path / "prod.env"
    env_file.write_text(f"DEEPSEEK_API_KEY={SECRET}\nOLLAMA_ENABLED=0\n", encoding="utf-8")

    def handler(request):
        if request.url.path.endswith("/chat/completions"):
            return httpx.Response(200, json=chat_completion(json.loads(request.content)["model"]))
        return httpx.Response(404)

    monkeypatch.setattr(doctor, "_load_settings", settings)
    monkeypatch.setattr(doctor, "_load_router", lambda: (lambda **kw: ["deepseek-v4-flash", "gpt-5-nano"], provider_of))

    environ = {}
    with mock_client(handler) as client:
        code = doctor.main(["--env-file", str(env_file), "--json"], client=client, environ=environ)
    out = capsys.readouterr().out
    report = json.loads(out)

    assert code == 0
    assert report["ok"] is True
    assert set(report["env_file_applied"]) == {"DEEPSEEK_API_KEY", "OLLAMA_ENABLED"}
    assert SECRET not in out
    statuses = {c["name"]: c["status"] for c in report["checks"]}
    assert statuses["modelark:chat[flash]"] == PASS and statuses["modelark:chat[pro]"] == PASS
    assert statuses["inference:service"] == WARN


def test_main_exit_code_is_nonzero_on_fail(capsys, monkeypatch):
    monkeypatch.setattr(doctor, "_load_settings", settings)
    monkeypatch.setattr(doctor, "_load_router", lambda: (lambda **kw: [], provider_of))
    with mock_client(lambda r: httpx.Response(500)) as client:
        code = doctor.main([], client=client, environ={})
    out = capsys.readouterr().out
    assert code == 1
    assert "FAIL  env:DEEPSEEK_API_KEY" in out
    assert out.strip().splitlines()[-1].startswith("---   FAIL:")
