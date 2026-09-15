"""rank_models() must prefer ModelArk DeepSeek over cheaper OpenAI models
(and a local Ollama model over both): the provider tier comes before cost.
Attributes are patched on the module (no reloads) so the tests compose with
test_doctor.py, which patches the same names."""
import policy_router


def _ranked(monkeypatch, *, deepseek, openai, ollama, tier="standard"):
    monkeypatch.setattr(policy_router, "DEEPSEEK_API_KEY", deepseek)
    monkeypatch.setattr(policy_router, "OPENAI_API_KEY", openai)
    monkeypatch.setattr(policy_router, "OLLAMA_ENABLED", ollama)
    return policy_router.rank_models(cost_ceiling=50.0, latency_max=None, tenant_tier=tier)


def _provider(model):
    return policy_router.MODEL_COST_TABLE[model]["provider"]


def test_modelark_outranks_openai_when_both_keys_present(monkeypatch):
    ranked = _ranked(monkeypatch, deepseek="k", openai="k", ollama=False)
    assert ranked[:2] == ["deepseek-v4-flash", "deepseek-v4-pro"]
    assert all(_provider(m) == "openai" for m in ranked[2:])


def test_local_ollama_still_first_when_enabled(monkeypatch):
    ranked = _ranked(monkeypatch, deepseek="k", openai="k", ollama=True)
    providers = [_provider(m) for m in ranked]
    assert providers[0] == "ollama"
    assert next(p for p in providers if p != "ollama") == "modelark"


def test_enterprise_tier_keeps_provider_preference(monkeypatch):
    ranked = _ranked(monkeypatch, deepseek="k", openai="k", ollama=False, tier="enterprise")
    assert ranked[0] == "deepseek-v4-pro"  # heaviest model within the preferred provider


def test_openai_only_when_no_deepseek_key(monkeypatch):
    ranked = _ranked(monkeypatch, deepseek="", openai="k", ollama=False)
    assert ranked and all(_provider(m) == "openai" for m in ranked)


def test_pinned_model_from_laravel_stays_primary(monkeypatch):
    """Laravel pins `model` from the inference.model flag; the pin wins and
    the fallbacks follow the provider-preference ranking."""
    from models import InferenceRequest

    monkeypatch.setattr(policy_router, "DEEPSEEK_API_KEY", "k")
    monkeypatch.setattr(policy_router, "OPENAI_API_KEY", "k")
    monkeypatch.setattr(policy_router, "OLLAMA_ENABLED", False)
    req = InferenceRequest(prompt="hi", tenant_id=1, model="deepseek-v4-pro")
    decision = policy_router.route(req)
    assert decision.primary == "deepseek-v4-pro"
    assert decision.fallbacks[0] == "deepseek-v4-flash"
