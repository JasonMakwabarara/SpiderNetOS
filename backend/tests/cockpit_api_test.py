"""Regression tests for the SpiderNetOS Cockpit FastAPI mock backend.

Tests the public preview URL through Kubernetes ingress (/api/* prefix).
"""
import os
import pytest
import requests

BASE_URL = (
    os.environ.get("REACT_APP_BACKEND_URL")
    or os.environ.get("VITE_API_URL")
    or "https://spidernet-cockpit.preview.emergentagent.com"
).rstrip("/")


@pytest.fixture(scope="session")
def client():
    s = requests.Session()
    s.headers.update({"Content-Type": "application/json"})
    return s


# ── Health ───────────────────────────────────────────────────────────────
class TestHealth:
    def test_health_ok(self, client):
        r = client.get(f"{BASE_URL}/api/health", timeout=10)
        assert r.status_code == 200
        data = r.json()
        assert data["status"] == "ok"
        assert "service" in data and "time" in data


# ── Auth ─────────────────────────────────────────────────────────────────
class TestAuth:
    def test_login_any_creds(self, client):
        r = client.post(
            f"{BASE_URL}/api/auth/login",
            json={"email": "operator@acme.ops", "password": "demo", "role": "super_admin"},
        )
        assert r.status_code == 200
        d = r.json()
        assert d["token"].startswith("mock.")
        assert d["user"]["email"] == "operator@acme.ops"
        assert d["user"]["role"] == "super_admin"
        assert d["tenant"]["id"] == "tnt_acme"
        assert isinstance(d["capabilities"], list) and len(d["capabilities"]) > 0

    def test_login_role_honored_admin(self, client):
        r = client.post(
            f"{BASE_URL}/api/auth/login",
            json={"email": "x@y.z", "password": "p", "role": "admin"},
        )
        assert r.status_code == 200
        assert r.json()["user"]["role"] == "admin"

    def test_login_role_honored_user(self, client):
        r = client.post(
            f"{BASE_URL}/api/auth/login",
            json={"email": "x@y.z", "password": "p", "role": "user"},
        )
        assert r.status_code == 200
        assert r.json()["user"]["role"] == "user"

    def test_login_invalid_role_falls_back(self, client):
        r = client.post(
            f"{BASE_URL}/api/auth/login",
            json={"email": "x@y.z", "password": "p", "role": "nonsense"},
        )
        assert r.status_code == 200
        assert r.json()["user"]["role"] == "super_admin"

    def test_login_empty_password(self, client):
        r = client.post(f"{BASE_URL}/api/auth/login", json={"email": "x@y.z", "password": ""})
        assert r.status_code == 422


# ── Envelope-wrapped list endpoints ─────────────────────────────────────
@pytest.mark.parametrize(
    "path",
    [
        "/api/agents",
        "/api/flows",
        "/api/approvals",
        "/api/traces",
        "/api/admin/users",
        "/api/intelligence/workers",
        "/api/memory",
        "/api/platform/feature-flags",
    ],
)
def test_list_endpoint_envelope(client, path):
    r = client.get(f"{BASE_URL}{path}", timeout=10)
    assert r.status_code == 200, f"{path} → {r.status_code}"
    body = r.json()
    assert "data" in body, f"{path} missing 'data' key: {body}"
    assert isinstance(body["data"], list), f"{path} 'data' is not a list"
    assert len(body["data"]) > 0, f"{path} returned empty list"


# ── Object/scalar endpoints ──────────────────────────────────────────────
class TestSingletonEndpoints:
    def test_usage_budget(self, client):
        r = client.get(f"{BASE_URL}/api/usage/budget")
        assert r.status_code == 200
        d = r.json()["data"]
        assert "monthly_limit" in d and "month_to_date_usd" in d
        assert isinstance(d["near_limit"], bool)

    def test_usage_current(self, client):
        r = client.get(f"{BASE_URL}/api/usage/current")
        assert r.status_code == 200
        d = r.json()["data"]
        for k in ("daily", "weekly", "monthly", "tokens_today", "requests_today"):
            assert k in d

    def test_platform_overview(self, client):
        r = client.get(f"{BASE_URL}/api/platform/overview")
        assert r.status_code == 200
        d = r.json()
        for k in ("tenants", "active_today", "total_agents", "total_flows", "feature_flags"):
            assert k in d

    def test_platform_ste_matrix(self, client):
        r = client.get(f"{BASE_URL}/api/platform/ste/matrix")
        assert r.status_code == 200
        d = r.json()
        assert "rows" in d and "cols" in d and "cells" in d
        assert len(d["cells"]) == len(d["rows"])
        assert all(len(row) == len(d["cols"]) for row in d["cells"])

    def test_billing(self, client):
        r = client.get(f"{BASE_URL}/api/billing")
        assert r.status_code == 200
        d = r.json()
        assert d["plan"]["name"] == "Ops Pro"
        assert isinstance(d["invoices"], list) and len(d["invoices"]) > 0
        assert d["payment_method"]["last4"] == "4242"

    def test_flow_detail(self, client):
        r = client.get(f"{BASE_URL}/api/flows/fl_2")
        assert r.status_code == 200
        d = r.json()["data"]
        assert d["id"] == "fl_2"
        assert isinstance(d["nodes"], list) and len(d["nodes"]) >= 3
        assert isinstance(d["edges"], list) and len(d["edges"]) >= 1


# ── Atlas ────────────────────────────────────────────────────────────────
class TestAtlas:
    def test_atlas_chat(self, client):
        r = client.post(
            f"{BASE_URL}/api/atlas/chat",
            json={"message": "hello", "session_id": "sess_test123"},
        )
        assert r.status_code == 200
        d = r.json()
        assert d["session_id"] == "sess_test123"
        assert d["agent"] == "Atlas Core"
        assert "model" in d and "tokens_used" in d

    def test_atlas_chat_new_session(self, client):
        r = client.post(f"{BASE_URL}/api/atlas/chat", json={"message": "hello"})
        assert r.status_code == 200
        assert r.json()["session_id"].startswith("sess_")


# ── Onboarding ───────────────────────────────────────────────────────────
class TestOnboarding:
    def test_step_save(self, client):
        r = client.post(
            f"{BASE_URL}/api/onboarding/step", json={"step": "welcome", "value": "ok"}
        )
        assert r.status_code == 200
        d = r.json()
        assert d["ok"] is True
        assert d["step"] == "welcome"


# ── Approvals ────────────────────────────────────────────────────────────
class TestApprovals:
    def test_approve(self, client):
        r = client.post(
            f"{BASE_URL}/api/approvals/apr_1/approve", json={"reason": "ok"}
        )
        assert r.status_code == 200
        d = r.json()
        assert d["ok"] is True
        assert d["id"] == "apr_1"
        assert d["status"] == "approved"
        assert d["reason"] == "ok"

    def test_reject(self, client):
        r = client.post(
            f"{BASE_URL}/api/approvals/apr_2/reject", json={"reason": "too risky"}
        )
        assert r.status_code == 200
        d = r.json()
        assert d["status"] == "rejected"
        assert d["reason"] == "too risky"
