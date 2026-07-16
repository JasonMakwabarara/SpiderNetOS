"""
SpiderNet OS — external test client.

Speaks ONLY the public HTTP contract:
    POST /api/auth/login      → Sanctum bearer token
    POST /api/atlas/chat      → v1 5-field Atlas response contract

Never imports backend code. Never touches Redis, Postgres, or the
Intelligence Worker directly. Designed for integration/smoke suites that
run against a live stack brought up with `docker compose up`.

Usage
-----
    from spidernet_client import SpiderNetClient

    c = SpiderNetClient()                              # reads SPIDERNET_URL
    c.login("admin@spidernet.local", "password")
    resp = c.chat("Hannah, how do I create a flow?")
    print(resp["message"]["contract"]["action_summary"])

Environment
-----------
    SPIDERNET_URL          Base URL of the Laravel API (default: http://localhost:8000)
    SPIDERNET_EMAIL        Default login email  (optional, used by helpers)
    SPIDERNET_PASSWORD     Default login password (optional)
    SPIDERNET_TIMEOUT      Per-request timeout seconds (default: 30)
    SPIDERNET_VERIFY_TLS   "0" to disable cert verification in dev (default: "1")
"""

from __future__ import annotations

import os
import uuid
from dataclasses import dataclass, field
from typing import Any, Optional

import requests


# ---------------------------------------------------------------------------
# Exceptions
# ---------------------------------------------------------------------------

class SpiderNetClientError(RuntimeError):
    """Base class for all client errors."""


class AuthError(SpiderNetClientError):
    """Login failed or token rejected."""


class ContractViolation(SpiderNetClientError):
    """Server response did not match the v1 Atlas contract."""


class RateLimited(SpiderNetClientError):
    """HTTP 429. Carries `retry_after_seconds` if the server supplied one."""

    def __init__(self, message: str, retry_after_seconds: Optional[int] = None):
        super().__init__(message)
        self.retry_after_seconds = retry_after_seconds


# ---------------------------------------------------------------------------
# Response DTO — optional convenience
# ---------------------------------------------------------------------------

@dataclass
class AtlasReply:
    """Typed view over /api/atlas/chat response. Raw body also preserved."""

    session_id: str
    interaction_id: str
    contract_version: str
    future_state: str
    value: str
    emotional_shift: str
    action_summary: str
    details: Optional[str]
    metadata: dict[str, Any]
    ast: Optional[dict[str, Any]]
    cost_status: Optional[dict[str, Any]]
    raw: dict[str, Any] = field(repr=False)

    @classmethod
    def from_body(cls, body: dict[str, Any]) -> "AtlasReply":
        msg = body["message"]
        contract = msg["contract"]
        return cls(
            session_id=body["session_id"],
            interaction_id=body["interaction_id"],
            contract_version=body["contract_version"],
            future_state=contract["future_state"],
            value=contract["value"],
            emotional_shift=contract["emotional_shift"],
            action_summary=contract["action_summary"],
            details=contract.get("details"),
            metadata=msg.get("metadata", {}),
            ast=body.get("ast"),
            cost_status=body.get("cost_status"),
            raw=body,
        )


# ---------------------------------------------------------------------------
# Client
# ---------------------------------------------------------------------------

class SpiderNetClient:
    """
    Minimal test client over the SpiderNet public HTTP contract.

    Invariants enforced on every /atlas/chat response:
      - contract_version == "1"
      - message.contract contains non-empty strings for:
          future_state, value, emotional_shift, action_summary
    """

    #: Fields the 5-field contract guarantees to be non-empty strings.
    REQUIRED_CONTRACT_FIELDS: frozenset[str] = frozenset({
        "future_state",
        "value",
        "emotional_shift",
        "action_summary",
    })

    def __init__(
        self,
        base_url: Optional[str] = None,
        *,
        timeout: Optional[float] = None,
        verify_tls: Optional[bool] = None,
        session: Optional[requests.Session] = None,
    ) -> None:
        self.base_url: str = (
            base_url or os.getenv("SPIDERNET_URL", "http://localhost:8000")
        ).rstrip("/")
        self.timeout: float = float(timeout if timeout is not None else os.getenv("SPIDERNET_TIMEOUT", "30"))

        if verify_tls is None:
            verify_tls = os.getenv("SPIDERNET_VERIFY_TLS", "1") != "0"
        self.verify_tls: bool = verify_tls

        self.session: requests.Session = session or requests.Session()
        self.session.headers.update({
            "Accept": "application/json",
            "User-Agent": "spidernet-test-client/1.0",
        })

        self.token: Optional[str] = None
        self.user: Optional[dict[str, Any]] = None
        self.tenant_id: Optional[str] = None
        self.session_id: Optional[str] = None

    # -------------------------------------------------------------------
    # Lifecycle
    # -------------------------------------------------------------------

    def ping(self) -> bool:
        """GET /up — Laravel health route registered in bootstrap/app.php."""
        try:
            r = self.session.get(
                f"{self.base_url}/up",
                timeout=self.timeout,
                verify=self.verify_tls,
            )
            return r.ok
        except requests.RequestException:
            return False

    def close(self) -> None:
        self.session.close()

    def __enter__(self) -> "SpiderNetClient":
        return self

    def __exit__(self, *_exc: object) -> None:
        self.close()

    # -------------------------------------------------------------------
    # Auth
    # -------------------------------------------------------------------

    def login(
        self,
        email: Optional[str] = None,
        password: Optional[str] = None,
    ) -> dict[str, Any]:
        """Exchange credentials for a Sanctum bearer token.

        Falls back to SPIDERNET_EMAIL / SPIDERNET_PASSWORD env vars when
        arguments are omitted — handy for CI secrets.
        """
        email = email or os.getenv("SPIDERNET_EMAIL")
        password = password or os.getenv("SPIDERNET_PASSWORD")
        if not email or not password:
            raise AuthError("email/password not provided (set SPIDERNET_EMAIL / SPIDERNET_PASSWORD)")

        try:
            r = self.session.post(
                f"{self.base_url}/api/auth/login",
                json={"email": email, "password": password},
                timeout=self.timeout,
                verify=self.verify_tls,
            )
        except requests.RequestException as e:
            raise AuthError(f"login request failed: {e}") from e

        if r.status_code == 429:
            raise RateLimited("login throttled", _retry_after(r))
        if not r.ok:
            raise AuthError(f"login rejected: HTTP {r.status_code} {r.text[:200]}")

        body = r.json()
        token = body.get("token")
        if not token:
            raise AuthError(f"login response missing token: {body!r}")

        self.token = token
        self.user = body.get("user")
        self.tenant_id = (self.user or {}).get("tenant_id")
        self.session.headers["Authorization"] = f"Bearer {token}"
        return self.user or {}

    def logout(self) -> None:
        """Best-effort logout — revokes the current token server-side."""
        if not self.token:
            return
        try:
            self.session.post(
                f"{self.base_url}/api/auth/logout",
                timeout=self.timeout,
                verify=self.verify_tls,
            )
        finally:
            self.token = None
            self.user = None
            self.tenant_id = None
            self.session.headers.pop("Authorization", None)

    def me(self) -> dict[str, Any]:
        """GET /api/auth/me — returns current user and tenant details.

        Used to verify Phase 1 onboarding fields: user.onboarding_completed_at
        and tenant.automation_level.
        """
        if not self.token:
            raise AuthError("not logged in — call .login() first")

        r = self.session.get(
            f"{self.base_url}/api/auth/me",
            timeout=self.timeout,
            verify=self.verify_tls,
        )

        if r.status_code == 401:
            raise AuthError("token rejected — re-authenticate")
        if not r.ok:
            raise SpiderNetClientError(f"me request failed: HTTP {r.status_code}")

        return r.json()

    # -------------------------------------------------------------------
    # The contract
    # -------------------------------------------------------------------

    def chat(
        self,
        message: str,
        *,
        style: str = "balanced",
        new_session: bool = False,
        session_id: Optional[str] = None,
        as_dto: bool = False,
    ) -> dict[str, Any] | AtlasReply:
        """POST /api/atlas/chat — the sole integration-test entry point.

        Parameters
        ----------
        message : str            User text, 1–4000 chars (server-validated).
        style : str              One of concise|balanced|emotional|analytical|directive.
        new_session : bool       Force a fresh session_id before sending.
        session_id : str | None  Explicit session id (overrides new_session).
        as_dto : bool            Return an AtlasReply dataclass instead of raw dict.

        Raises
        ------
        AuthError              Token missing or rejected.
        RateLimited            HTTP 429.
        ContractViolation      Response body does not match v1 contract.
        SpiderNetClientError   Other non-2xx responses.
        """
        if not self.token:
            raise AuthError("not logged in — call .login() first")

        if session_id is not None:
            self.session_id = session_id
        elif new_session or self.session_id is None:
            self.session_id = str(uuid.uuid4())

        payload = {
            "message": message,
            "session_id": self.session_id,
            "style": style,
        }

        try:
            r = self.session.post(
                f"{self.base_url}/api/atlas/chat",
                json=payload,
                timeout=self.timeout,
                verify=self.verify_tls,
            )
        except requests.RequestException as e:
            raise SpiderNetClientError(f"chat request failed: {e}") from e

        if r.status_code == 401:
            raise AuthError("token rejected (401)")
        if r.status_code == 429:
            raise RateLimited("chat throttled", _retry_after(r))
        if not r.ok:
            raise SpiderNetClientError(f"chat failed: HTTP {r.status_code} {r.text[:400]}")

        body = r.json()
        self._assert_contract(body)

        # Keep session_id in sync if server rotated it.
        self.session_id = body.get("session_id", self.session_id)

        return AtlasReply.from_body(body) if as_dto else body

    def reset_session(self) -> str:
        """Start a new conversation — next chat() gets a fresh session_id."""
        self.session_id = str(uuid.uuid4())
        return self.session_id

    # -------------------------------------------------------------------
    # Contract guard
    # -------------------------------------------------------------------

    def _assert_contract(self, body: dict[str, Any]) -> None:
        if not isinstance(body, dict):
            raise ContractViolation(f"response is not a JSON object: {type(body).__name__}")

        if body.get("contract_version") != "1":
            raise ContractViolation(
                f"unexpected contract_version: {body.get('contract_version')!r}"
            )

        msg = body.get("message")
        if not isinstance(msg, dict):
            raise ContractViolation("response.message missing or not an object")

        contract = msg.get("contract")
        if not isinstance(contract, dict):
            raise ContractViolation("response.message.contract missing or not an object")

        missing = self.REQUIRED_CONTRACT_FIELDS - contract.keys()
        if missing:
            raise ContractViolation(f"contract missing fields: {sorted(missing)}")

        for field_name in self.REQUIRED_CONTRACT_FIELDS:
            v = contract[field_name]
            if not isinstance(v, str) or not v.strip():
                raise ContractViolation(
                    f"contract.{field_name} must be a non-empty string, got {v!r}"
                )

        # session_id / interaction_id are UUIDs per AtlasController
        for key in ("session_id", "interaction_id"):
            if not isinstance(body.get(key), str) or not body[key]:
                raise ContractViolation(f"response.{key} missing or not a string")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _retry_after(response: requests.Response) -> Optional[int]:
    """Parse Retry-After header as seconds, if present and numeric."""
    raw = response.headers.get("Retry-After")
    if not raw:
        return None
    try:
        return int(raw)
    except ValueError:
        return None
