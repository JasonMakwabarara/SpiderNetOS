"""Pytest fixtures for live-stack SpiderNet smoke tests.

The suite auto-skips when the stack is not reachable, so running
`pytest tests/client/` on a dev machine without `docker compose up`
reports "skipped" instead of failing noisily.
"""

from __future__ import annotations

import os

import pytest

from spidernet_client import AuthError, SpiderNetClient


DEFAULT_EMAIL = os.getenv("SPIDERNET_EMAIL", "admin@spidernet.local")
DEFAULT_PASSWORD = os.getenv("SPIDERNET_PASSWORD", "password")


@pytest.fixture(scope="session")
def live_stack_url() -> str:
    """Skip the session if the API is unreachable."""
    url = os.getenv("SPIDERNET_URL", "http://localhost:8000")
    probe = SpiderNetClient(base_url=url, timeout=2)
    try:
        if not probe.ping():
            pytest.skip(f"SpiderNet API not reachable at {url} — start stack with `docker compose up`")
    finally:
        probe.close()
    return url


@pytest.fixture()
def client(live_stack_url: str) -> SpiderNetClient:
    """Unauthenticated client, one per test."""
    with SpiderNetClient(base_url=live_stack_url) as c:
        yield c


@pytest.fixture()
def authed_client(client: SpiderNetClient) -> SpiderNetClient:
    """Client already logged in as the default seeded admin user."""
    try:
        client.login(DEFAULT_EMAIL, DEFAULT_PASSWORD)
    except AuthError as e:
        pytest.skip(f"cannot authenticate as {DEFAULT_EMAIL}: {e}")
    return client
