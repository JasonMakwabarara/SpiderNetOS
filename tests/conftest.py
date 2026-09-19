"""Shared fixtures for the repository-level test suites.

`services/cpl-service` contains a hyphen, so `import services.cpl_service`
cannot resolve and never could — which is why the tests that tried it had never
run. Load those modules by path instead.
"""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parent.parent


def load_module(relative_path: str, name: str):
    """Import a module from a path that is not a legal package name."""
    location = REPO_ROOT / relative_path
    if not location.exists():
        pytest.skip(f"{relative_path} is not present in this checkout")

    spec = importlib.util.spec_from_file_location(name, location)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="session")
def cpl_policy_network():
    """The real PolicyNetwork from services/cpl-service."""
    return load_module("services/cpl-service/engine/policy_network.py", "cpl_policy_network")


@pytest.fixture(scope="session")
def cpl_cost_governor():
    """The real CostGovernor from services/cpl-service."""
    return load_module("services/cpl-service/engine/cost_governor.py", "cpl_cost_governor")


class _FakeRedis:
    """Enough of redis.asyncio for CostGovernor's spend lookup."""

    def __init__(self, costs: dict[str, str] | None = None):
        self._costs = costs or {}

    async def hgetall(self, key: str) -> dict[str, str]:
        return self._costs


@pytest.fixture
def fake_redis():
    """Build a stand-in redis holding a day's recorded costs.

    A fixture rather than an importable class: `from conftest import ...`
    resolves to whichever conftest pytest happened to load first, which is a
    different file depending on the directory you run from.
    """
    return _FakeRedis


# Container fixtures live here, not in tests/integration/, so that a test
# anywhere in the tree can ask for one and be skipped cleanly when
# testcontainers is not installed. tests/behavioral asked for kafka_container
# and errored instead, because the fixture was one directory away.
def _container(factory):
    pytest.importorskip("testcontainers", reason="integration suite: pip install testcontainers")
    return factory()


@pytest.fixture(scope="session")
def postgres_container():
    def make():
        from testcontainers.postgres import PostgresContainer
        with PostgresContainer(
            image="pgvector/pgvector:pg16",
            username="spidernet",
            password="test_password",
            dbname="spidernet",
        ) as postgres:
            yield postgres.get_connection_url()
    yield from _container(make)


@pytest.fixture(scope="session")
def redis_container():
    def make():
        from testcontainers.redis import RedisContainer
        with RedisContainer(image="redis:7-alpine") as redis:
            yield redis.get_connection_url()
    yield from _container(make)


@pytest.fixture(scope="session")
def kafka_container():
    def make():
        from testcontainers.kafka import KafkaContainer
        with KafkaContainer(image="confluentinc/cp-kafka:7.5.0") as kafka:
            yield kafka.get_bootstrap_server()
    yield from _container(make)
