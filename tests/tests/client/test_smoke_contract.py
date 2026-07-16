"""Smoke tests for the SpiderNet public HTTP contract.

Run:
    docker compose up -d
    pip install -r tests/client/requirements.txt
    pytest tests/client/ -v

These tests intentionally assert ONLY what the v1 Atlas contract
guarantees. Do not tighten assertions against LLM-generated text —
the contract is structural, not semantic.
"""

from __future__ import annotations

import pytest

from spidernet_client import AtlasReply, ContractViolation, SpiderNetClient


# ---------------------------------------------------------------------------
# Health
# ---------------------------------------------------------------------------

def test_health_endpoint_reachable(client: SpiderNetClient) -> None:
    assert client.ping() is True


# ---------------------------------------------------------------------------
# Auth
# ---------------------------------------------------------------------------

def test_chat_without_login_raises_auth_error(client: SpiderNetClient) -> None:
    from spidernet_client import AuthError

    with pytest.raises(AuthError):
        client.chat("hello")


def test_login_yields_bearer_token(authed_client: SpiderNetClient) -> None:
    assert authed_client.token, "login must populate bearer token"
    assert authed_client.session.headers.get("Authorization", "").startswith("Bearer ")


# ---------------------------------------------------------------------------
# Contract shape
# ---------------------------------------------------------------------------

def test_chat_returns_v1_contract(authed_client: SpiderNetClient) -> None:
    body = authed_client.chat("Hannah, how do I create a flow?")

    assert body["contract_version"] == "1"
    contract = body["message"]["contract"]
    for field in SpiderNetClient.REQUIRED_CONTRACT_FIELDS:
        assert isinstance(contract[field], str) and contract[field].strip(), \
            f"contract.{field} must be a non-empty string"


def test_chat_dto_projection(authed_client: SpiderNetClient) -> None:
    reply = authed_client.chat("Hannah, explain agents", as_dto=True)

    assert isinstance(reply, AtlasReply)
    assert reply.contract_version == "1"
    assert reply.future_state and reply.value and reply.emotional_shift and reply.action_summary
    assert reply.session_id == authed_client.session_id


# ---------------------------------------------------------------------------
# Session continuity
# ---------------------------------------------------------------------------

def test_same_session_id_persists_across_turns(authed_client: SpiderNetClient) -> None:
    first  = authed_client.chat("Hannah, explain memory graph")
    second = authed_client.chat("give me a shorter version")  # same session

    assert first["session_id"] == second["session_id"]
    assert first["interaction_id"] != second["interaction_id"]


def test_reset_session_rotates_id(authed_client: SpiderNetClient) -> None:
    first = authed_client.chat("Hannah, hello")
    authed_client.reset_session()
    second = authed_client.chat("Hannah, hello again")

    assert first["session_id"] != second["session_id"]


# ---------------------------------------------------------------------------
# Phase 1: Onboarding contract (automation_level in tenant, onboarding_completed_at)
# ---------------------------------------------------------------------------

def test_me_endpoint_returns_onboarding_fields(authed_client: SpiderNetClient) -> None:
    """Verify /api/auth/me returns onboarding-related fields for Phase 1."""
    me = authed_client.me()

    # User must have onboarding_completed_at field (nullable)
    assert 'onboarding_completed_at' in me['user'], \
        "user.onboarding_completed_at must be present (nullable)"

    # Tenant must have automation_level field
    assert 'automation_level' in me['tenant'], \
        "tenant.automation_level must be present"
    assert me['tenant']['automation_level'] in {'manual', 'assisted', 'autonomous'}, \
        f"automation_level must be one of manual/assisted/autonomous, got: {me['tenant']['automation_level']!r}"


# ---------------------------------------------------------------------------
# Metadata contract (non-breaking fields we expose for observability)
# ---------------------------------------------------------------------------

def test_response_metadata_surfaces_agent_and_status(authed_client: SpiderNetClient) -> None:
    body = authed_client.chat("Hannah, status please")
    meta = body["message"]["metadata"]

    assert meta.get("agent_used"), "metadata.agent_used must identify the answering agent"
    assert meta.get("status") in {"received", "success", "blocked"}, \
        f"unexpected status: {meta.get('status')!r}"
    assert meta.get("style")  in {"concise", "balanced", "emotional", "analytical", "directive"}


# ---------------------------------------------------------------------------
# Client-side guard — synthetic violation must raise
# ---------------------------------------------------------------------------

def test_contract_guard_rejects_missing_field() -> None:
    c = SpiderNetClient(base_url="http://unused")
    bad = {
        "contract_version": "1",
        "session_id": "s",
        "interaction_id": "i",
        "message": {"contract": {"future_state": "x", "value": "y", "emotional_shift": "z"}},
    }
    with pytest.raises(ContractViolation):
        c._assert_contract(bad)  # type: ignore[attr-defined]


def test_contract_guard_rejects_wrong_version() -> None:
    c = SpiderNetClient(base_url="http://unused")
    bad = {
        "contract_version": "2",
        "session_id": "s",
        "interaction_id": "i",
        "message": {"contract": {
            "future_state": "a", "value": "b",
            "emotional_shift": "c", "action_summary": "d",
        }},
    }
    with pytest.raises(ContractViolation):
        c._assert_contract(bad)  # type: ignore[attr-defined]
