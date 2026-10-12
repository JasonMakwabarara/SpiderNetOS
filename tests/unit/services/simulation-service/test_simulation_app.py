"""Boot the simulation service's FastAPI app the way its image lays it out.

The image runs main.py from services/simulation-service with /app (holding
`shared`) on PYTHONPATH. Before the engine and agent modules existed the import
failed outright, and `Field(regex=...)` would have failed it again on
pydantic 2.
"""

import asyncio
import math
import sys
from pathlib import Path

import pytest

pytest.importorskip("fastapi")
pytest.importorskip("httpx")
pytest.importorskip("kafka")

from fastapi.testclient import TestClient  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parents[4]
SERVICE_DIR = REPO_ROOT / "services" / "simulation-service"
TOP_LEVEL = ("main", "agents", "engine", "shared")


class _FakeProducer:
    sent = []

    def __init__(self, bootstrap_servers):
        self.bootstrap_servers = bootstrap_servers

    def start(self):
        pass

    def stop(self):
        pass

    async def send(self, topic, value):
        self.sent.append((topic, value))


def _forget_service_modules():
    for name in list(sys.modules):
        if name.split(".")[0] in TOP_LEVEL:
            del sys.modules[name]


@pytest.fixture
def client(monkeypatch, tmp_path):
    _forget_service_modules()
    monkeypatch.syspath_prepend(str(REPO_ROOT / "services"))
    monkeypatch.syspath_prepend(str(SERVICE_DIR))
    monkeypatch.setenv("POLICY_REGISTRY_PATH", str(tmp_path / "policies"))
    monkeypatch.setenv("KAFKA_BOOTSTRAP", "kafka.test:29092")
    # Nothing listens on port 1, so the scenario generator falls back at once.
    monkeypatch.setenv("OLLAMA_URL", "http://127.0.0.1:1")

    import main

    monkeypatch.setattr(main, "EventProducer", _FakeProducer)
    _FakeProducer.sent = []
    with TestClient(main.app) as test_client:
        yield test_client, main
    _forget_service_modules()


@pytest.mark.unit
def test_boots_with_every_component(client):
    test_client, main = client
    body = test_client.get("/health").json()

    assert all(body["components"].values()), body["components"]
    assert body["active_scenario"], "lifespan must set the module-level scenario"
    assert main.event_producer.bootstrap_servers == "kafka.test:29092"


@pytest.mark.unit
def test_campaign_must_be_registered_before_execution(client):
    test_client, _ = client
    action = {"campaign_id": "c1", "budget_allocation": 100.0, "channel_mix": {"meta": 1.0}}

    assert test_client.post("/campaign/execute", json=action).status_code == 404

    registered = test_client.post("/campaign/register", json={"campaign_id": "c1", "budget_daily": 500.0})
    assert registered.status_code == 200

    executed = test_client.post("/campaign/execute", json=action)
    assert executed.status_code == 200
    assert executed.json()["results"]["channels"]["meta"]["status"] == "planned"
    assert _FakeProducer.sent[-1][0] == "execution.outcome"

    too_much = dict(action, budget_allocation=900.0)
    assert test_client.post("/campaign/execute", json=too_much).status_code == 422


@pytest.mark.unit
def test_policy_register_deploy_and_bad_ids(client):
    test_client, _ = client

    created = test_client.post("/policy/register", json={"version_id": "v1", "description": "first"})
    assert created.status_code == 200
    assert created.json()["status"] == "registered"

    assert test_client.post("/policy/register", json={"version_id": "v1", "description": "again"}).status_code == 409
    assert test_client.post("/policy/register", json={"version_id": "../x", "description": "x"}).status_code == 422

    deployed = test_client.post("/policy/deploy", json={"version_id": "v1"})
    assert deployed.status_code == 200
    assert test_client.post("/policy/deploy", json={"version_id": "nope"}).status_code == 404

    assert test_client.get("/metrics").json()["policy"]["versions"] == 1


@pytest.mark.unit
def test_training_episode_runs_and_reports(client):
    _, main = client
    loop = asyncio.new_event_loop()
    try:
        loop.run_until_complete(main._run_training_simulation(episodes=1, budget=1000.0, randomize=True))
    finally:
        loop.close()

    topic, event = _FakeProducer.sent[-1]
    assert topic == "simulation.episode.end"
    assert math.isfinite(event["total_reward"])


@pytest.mark.unit
def test_difficulty_is_validated(client):
    test_client, _ = client
    response = test_client.post("/simulation/start", json={"difficulty": "impossible"})
    assert response.status_code == 422
