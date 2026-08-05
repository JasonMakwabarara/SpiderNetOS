"""End-to-end tests for the SpiderNetOS enterprise onboarding + cockpit backend.

Covers /api/enterprise/* and /api/scim/v2/* against the public preview URL.
"""
import os
import zipfile
import io
import pytest
import requests

BASE_URL = (os.environ.get("REACT_APP_BACKEND_URL") or "").rstrip("/")
assert BASE_URL, "REACT_APP_BACKEND_URL must be set"


@pytest.fixture(scope="session")
def client():
    s = requests.Session()
    s.headers.update({"Content-Type": "application/json"})
    return s


@pytest.fixture(scope="session")
def shared_state():
    return {}


# ── Health ───────────────────────────────────────────────────────────────
class TestHealth:
    def test_enterprise_health(self, client):
        r = client.get(f"{BASE_URL}/api/enterprise/health", timeout=15)
        assert r.status_code == 200
        d = r.json()
        assert d["ok"] is True
        assert "db" in d and "ts" in d


# ── Registration wizard 10-step flow ─────────────────────────────────────
class TestRegistrationWizard:
    def test_step1_register_start(self, client, shared_state):
        payload = {
            "org_name": "TEST_AcmeCo",
            "contact_email": "TEST_admin@acme-test.example",
            "contact_name": "Test Admin",
            "domain": "acme-test.example",
        }
        r = client.post(f"{BASE_URL}/api/enterprise/register/start", json=payload, timeout=15)
        assert r.status_code == 200, r.text
        d = r.json()
        assert d["enterprise_id"].startswith("ent_")
        assert d["domain"] == "acme-test.example"
        assert d["domain_token"].startswith("sn_")
        shared_state["enterprise_id"] = d["enterprise_id"]
        shared_state["domain"] = d["domain"]

    def test_step2_verify_domain(self, client, shared_state):
        eid = shared_state["enterprise_id"]
        r = client.post(
            f"{BASE_URL}/api/enterprise/register/verify-domain",
            json={"enterprise_id": eid, "method": "auto"},
            timeout=15,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        assert d["verified"] is True
        assert d["domain"] == shared_state["domain"]

    def test_step2_verify_domain_unknown_enterprise_404(self, client):
        r = client.post(
            f"{BASE_URL}/api/enterprise/register/verify-domain",
            json={"enterprise_id": "ent_doesnotexist", "method": "auto"},
            timeout=15,
        )
        assert r.status_code == 404

    def test_step3_create_tenant(self, client, shared_state):
        r = client.post(
            f"{BASE_URL}/api/enterprise/register/create-tenant",
            json={"enterprise_id": shared_state["enterprise_id"], "region": "us-east-1"},
            timeout=20,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        tenant = d["tenant"]
        assert tenant["id"].startswith("tnt_")
        assert tenant["region"] == "us-east-1"
        assert tenant["plan"] == "Enterprise"
        assert "_id" not in tenant
        shared_state["tenant_id"] = tenant["id"]

    def test_step6_scim_generate(self, client, shared_state):
        r = client.post(
            f"{BASE_URL}/api/enterprise/register/scim/generate",
            json={
                "enterprise_id": shared_state["enterprise_id"],
                "tenant_id": shared_state["tenant_id"],
            },
            timeout=15,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        # token returned only once at generation time
        token = d.get("scim_token") or d.get("token")
        assert token and len(token) > 20, d
        assert "scim_base_url" in d
        shared_state["scim_token"] = token

    def test_step9_bundle_create_register(self, client, shared_state):
        r = client.post(
            f"{BASE_URL}/api/enterprise/register/bundle/create",
            json={
                "enterprise_id": shared_state["enterprise_id"],
                "tenant_id": shared_state["tenant_id"],
                "target": "linux-x86_64",
                "components": ["runtime", "connectors", "cockpit-agent"],
            },
            timeout=30,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        assert "bundle_id" in d
        # SHA-256 hex = 64 chars
        assert "sha256" in d and len(d["sha256"]) == 64
        assert "signature" in d and len(d["signature"]) > 20
        assert d.get("download_path", "").startswith("/api/enterprise/aios/bundle/")
        shared_state["bundle_id"] = d["bundle_id"]
        shared_state["bundle_sha256"] = d["sha256"]
        shared_state["bundle_signature"] = d["signature"]

    def test_step10_deploy_start(self, client, shared_state):
        r = client.post(
            f"{BASE_URL}/api/enterprise/register/deploy/start",
            json={"bundle_id": shared_state["bundle_id"]},
            timeout=15,
        )
        # 200 OK on demo deploy start
        assert r.status_code == 200, r.text


# ── AIOS bundle download / verify ───────────────────────────────────────
class TestAiosBundle:
    def test_bundle_download_real_zip(self, client, shared_state):
        bid = shared_state.get("bundle_id")
        assert bid, "bundle_id should be set from earlier test"
        r = client.get(
            f"{BASE_URL}/api/enterprise/aios/bundle/{bid}/download", timeout=30, stream=False
        )
        assert r.status_code == 200, r.text
        ct = r.headers.get("Content-Type", "")
        assert "application/zip" in ct, f"got Content-Type={ct!r}"
        # Signature/sha256 headers
        sha = r.headers.get("X-SpiderNet-SHA256") or r.headers.get("x-spidernet-sha256")
        sig = r.headers.get("X-SpiderNet-Signature") or r.headers.get("x-spidernet-signature")
        assert sha and len(sha) == 64, f"missing/invalid SHA-256 header: {sha!r}"
        assert sig and len(sig) > 20, f"missing/invalid signature header: {sig!r}"
        # And it must be a real zip
        zf = zipfile.ZipFile(io.BytesIO(r.content))
        names = zf.namelist()
        assert len(names) > 0, "zip is empty"
        # Manifest should usually be present
        assert any("manifest" in n.lower() for n in names), f"no manifest in zip: {names}"

    def test_bundle_verify(self, client, shared_state):
        bid = shared_state.get("bundle_id")
        r = client.get(f"{BASE_URL}/api/enterprise/aios/bundle/{bid}/verify", timeout=15)
        assert r.status_code == 200, r.text
        d = r.json()
        # Expect at least sha256 in the verify response
        assert "sha256" in d or "valid" in d or "ok" in d

    def test_bundles_list(self, client, shared_state):
        tid = shared_state.get("tenant_id")
        r = client.get(
            f"{BASE_URL}/api/enterprise/aios/bundles", params={"tenant_id": tid}, timeout=15
        )
        assert r.status_code == 200, r.text
        d = r.json()
        # accept either list or {bundles:[...]}
        bundles = d if isinstance(d, list) else d.get("bundles", d.get("data", []))
        assert isinstance(bundles, list)
        assert any(b.get("id") == shared_state["bundle_id"] for b in bundles), bundles

    def test_aios_bundle_create_cockpit(self, client, shared_state):
        tid = shared_state.get("tenant_id")
        r = client.post(
            f"{BASE_URL}/api/enterprise/aios/bundle/create",
            json={"tenant_id": tid, "target": "linux-x86_64", "components": ["runtime"]},
            timeout=30,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        assert "bundle_id" in d
        assert len(d["sha256"]) == 64
        assert "signature" in d


# ── Cockpit overview + listing endpoints ────────────────────────────────
class TestCockpit:
    def test_cockpit_overview(self, client):
        r = client.get(f"{BASE_URL}/api/enterprise/cockpit/overview", timeout=15)
        assert r.status_code == 200, r.text
        d = r.json()
        # accept a few possible shapes — just ensure dict with some metric keys
        assert isinstance(d, dict) and len(d) > 0

    def test_tenants_list(self, client, shared_state):
        r = client.get(f"{BASE_URL}/api/enterprise/tenants", timeout=15)
        assert r.status_code == 200
        d = r.json()
        tenants = d if isinstance(d, list) else d.get("tenants", d.get("data", []))
        assert isinstance(tenants, list)
        assert any(t.get("id") == shared_state.get("tenant_id") for t in tenants)

    def test_connectors_list_for_tenant(self, client, shared_state):
        tid = shared_state.get("tenant_id")
        r = client.get(
            f"{BASE_URL}/api/enterprise/connectors", params={"tenant_id": tid}, timeout=15
        )
        assert r.status_code == 200
        d = r.json()
        connectors = d if isinstance(d, list) else d.get("connectors", d.get("data", []))
        assert isinstance(connectors, list)
        # seeded 4 default connectors after tenant create
        assert len(connectors) >= 4

    def test_audit_list(self, client):
        r = client.get(f"{BASE_URL}/api/enterprise/audit", timeout=15)
        assert r.status_code == 200
        d = r.json()
        events = d if isinstance(d, list) else d.get("events", d.get("data", []))
        assert isinstance(events, list)
        assert len(events) > 0


# ── Auth: magic link, totp, webauthn, sso demo ──────────────────────────
class TestAuth:
    def test_magic_link_request_and_verify(self, client, shared_state):
        email = "TEST_magic@acme-test.example"
        r = client.post(
            f"{BASE_URL}/api/enterprise/auth/magic-link/request",
            json={"email": email},
            timeout=15,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        assert d["sent"] is True
        token = d["dev_link"]["token"]
        assert token

        r2 = client.post(
            f"{BASE_URL}/api/enterprise/auth/magic-link/verify",
            json={"token": token},
            timeout=15,
        )
        assert r2.status_code == 200, r2.text
        sess = r2.json()
        assert "access_token" in sess
        assert sess["user"]["email"] == email
        assert "tenant" in sess

        # one-time use → second verify should fail
        r3 = client.post(
            f"{BASE_URL}/api/enterprise/auth/magic-link/verify",
            json={"token": token},
            timeout=15,
        )
        assert r3.status_code == 400

    def test_totp_demo_bypass(self, client):
        r = client.post(
            f"{BASE_URL}/api/enterprise/auth/totp/login",
            json={"email": "TEST_totp@acme-test.example", "code": "000000"},
            timeout=15,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        assert "access_token" in d
        assert d["user"]["email"] == "TEST_totp@acme-test.example"

    def test_totp_invalid_code(self, client):
        r = client.post(
            f"{BASE_URL}/api/enterprise/auth/totp/login",
            json={"email": "TEST_totp2@acme-test.example", "code": "123456"},
            timeout=15,
        )
        assert r.status_code == 401

    def test_webauthn_demo(self, client):
        r = client.post(
            f"{BASE_URL}/api/enterprise/auth/webauthn/login",
            json={"email": "TEST_webauthn@acme-test.example"},
            timeout=15,
        )
        assert r.status_code == 200, r.text
        assert "access_token" in r.json()

    def test_sso_demo_start_completes(self, client):
        r = client.post(
            f"{BASE_URL}/api/enterprise/auth/sso/start",
            json={"tenant_slug": "demo", "provider": "oidc-demo"},
            timeout=15,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        assert d.get("completed") is True
        assert "access_token" in d
        assert "tenant" in d and d["tenant"]["slug"] == "demo"


# ── SCIM 2.0 ─────────────────────────────────────────────────────────────
class TestScim:
    def test_service_provider_config_public(self, client):
        r = client.get(f"{BASE_URL}/api/scim/v2/ServiceProviderConfig", timeout=15)
        assert r.status_code == 200, r.text
        # should be SCIM schema-ish (dict)
        assert isinstance(r.json(), dict)

    def test_users_requires_bearer(self, client):
        # fresh session w/o auth headers
        r = requests.get(f"{BASE_URL}/api/scim/v2/Users", timeout=15)
        assert r.status_code == 401, f"expected 401 unauth, got {r.status_code}: {r.text}"

    def test_users_with_valid_token(self, client, shared_state):
        token = shared_state.get("scim_token")
        assert token, "scim token should have been generated in wizard step 6"
        r = requests.get(
            f"{BASE_URL}/api/scim/v2/Users",
            headers={"Authorization": f"Bearer {token}"},
            timeout=15,
        )
        assert r.status_code == 200, r.text
        d = r.json()
        # SCIM ListResponse-ish
        assert isinstance(d, dict)
        assert "Resources" in d or "resources" in d or "totalResults" in d
