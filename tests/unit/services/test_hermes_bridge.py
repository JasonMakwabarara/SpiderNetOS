"""The Hermes bridge, its Redis dispatch client and its API routes.

The bridge imported a MetaPlanner and MemoryGraph that do not exist in the
services tree, dispatched to an agent called `dynamic` that no worker runs,
and the routes module called `router.exception_handler`, which APIRouter does
not have, so nothing here could even be imported. A fake Redis plays the
intelligence worker's side of the protocol.
"""

import asyncio
import json
import sys
from pathlib import Path

import pytest

pytest.importorskip("fastapi")
pytest.importorskip("httpx")

REPO_ROOT = Path(__file__).resolve().parents[3]
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))

import httpx  # noqa: E402
from fastapi import FastAPI  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from services.shared import hermes_bridge as bridge_mod  # noqa: E402
from services.shared.agent_dispatch import AgentDispatcher  # noqa: E402

# Capabilities the worker's agents hold, as the MetaPlanner checks them.
CAN_HANDLE = {
    ('atlas', 'chat'), ('nexus', 'execute_flow'), ('prism', 'analyze_data'),
}


def worker_result(job):
    """What intelligence/main.py publishes for a job."""
    if (job['agent_id'], job['intent']) not in CAN_HANDLE:
        return {'status': 'blocked', 'reason': f"Agent lacks permission for intent: {job['intent']}"}
    output = {'response': f"{job['agent_id']} handled: {job['context']['original_message']}"}
    if job['agent_id'] == 'nexus':
        output['workflow_id'] = 'wf-1'
    return {'status': 'success', 'result': {'status': 'success', 'output': output, 'tokens_used': 3}}


class FakePubSub:
    def __init__(self, redis):
        self.redis = redis
        self.queue = asyncio.Queue()
        self.channels = set()

    async def subscribe(self, channel):
        self.channels.add(channel)
        self.redis.subscribers.append(self)

    async def unsubscribe(self, channel):
        self.channels.discard(channel)

    async def get_message(self, ignore_subscribe_messages=True, timeout=None):
        try:
            return await asyncio.wait_for(self.queue.get(), timeout)
        except asyncio.TimeoutError:
            return None

    async def aclose(self):
        self.redis.subscribers.remove(self)


class FakeRedis:
    def __init__(self, worker=worker_result):
        self.lists = {}
        self.subscribers = []
        self.worker = worker
        self.jobs = []

    def pubsub(self):
        return FakePubSub(self)

    async def publish(self, channel, data):
        for sub in list(self.subscribers):
            if channel in sub.channels:
                sub.queue.put_nowait({'type': 'message', 'channel': channel, 'data': data})

    async def rpush(self, key, *values):
        if key == 'agent:dispatch':
            for value in values:
                job = json.loads(value)
                self.jobs.append(job)
                result = self.worker(job)
                if result is not None:
                    # A stray result for someone else first: it must be ignored.
                    await self.publish(f"agent:result:{job['tenant_id']}", json.dumps(
                        {'event_id': 'someone-else', 'result': {'status': 'error'}}))
                    await self.publish(f"agent:result:{job['tenant_id']}", json.dumps(
                        {'event_id': job['event_id'], 'result': result}))
            return len(values)
        self.lists.setdefault(key, []).extend(values)
        return len(self.lists[key])

    async def ltrim(self, key, start, end):
        items = self.lists.get(key, [])
        n = len(items)
        start = start + n if start < 0 else start
        end = end + n if end < 0 else end
        self.lists[key] = items[max(start, 0):end + 1]

    async def lrange(self, key, start, end):
        items = self.lists.get(key, [])
        return items[start:end + 1 if end >= 0 else None]


def run(coro):
    loop = asyncio.new_event_loop()
    try:
        return loop.run_until_complete(coro)
    finally:
        loop.close()


def make_bridge(redis=None, http=None, timeout=2.0):
    redis = redis or FakeRedis()
    return bridge_mod.HermesIntegrationBridge(
        AgentDispatcher(redis, timeout=timeout), redis, sync_interval=0, http_client=http,
    ), redis


class TestAgentDispatcher:
    def test_round_trip_matches_its_own_event(self):
        redis = FakeRedis()
        result = run(AgentDispatcher(redis).dispatch('t1', 'atlas', 'chat', 'hello', session_id='s1'))

        assert result['status'] == 'success'
        job = redis.jobs[0]
        assert job['tenant_id'] == 't1'
        assert job['context']['original_message'] == 'hello', "the worker reads original_message"
        assert job['context']['session_id'] == 's1'
        assert redis.subscribers == [], "the subscription is released"

    def test_times_out_when_no_worker_answers(self):
        redis = FakeRedis(worker=lambda job: None)
        result = run(AgentDispatcher(redis, timeout=0.2).dispatch('t1', 'atlas', 'chat', 'hi'))
        assert result['status'] == 'timeout'

    def test_tenant_is_required(self):
        with pytest.raises(ValueError):
            run(AgentDispatcher(FakeRedis()).dispatch('', 'atlas', 'chat', 'hi'))


class TestBridge:
    @pytest.mark.parametrize('message,agents', [
        ('hello there', ['atlas']),
        ('please orchestrate the onboarding workflow', ['nexus']),
        ('analyze last week of sales data', ['prism', 'atlas']),
        ('connect our webhook', ['nexus']),
    ])
    def test_every_route_is_one_the_worker_accepts(self, message, agents):
        bridge, redis = make_bridge()
        result = run(bridge.coordinate_request({'tenant_id': 't1', 'message': message}))

        assert result['status'] == 'success', result['individual_results']
        assert result['agents_used'] == agents
        assert all(job['tenant_id'] == 't1' for job in redis.jobs)

    def test_single_agent_response_is_the_agents_text(self):
        bridge, _ = make_bridge()
        result = run(bridge.coordinate_request({'tenant_id': 't1', 'message': 'hello'}))
        assert result['response'] == 'atlas handled: hello'

    def test_workflow_id_is_reported(self):
        bridge, _ = make_bridge()
        result = run(bridge.coordinate_request({'tenant_id': 't1', 'message': 'run the workflow'}))
        assert result['workflow_triggers'] == ['wf-1']

    def test_partial_and_failed_outcomes(self):
        def prism_down(job):
            if job['agent_id'] == 'prism':
                return {'status': 'error', 'reason': 'boom'}
            return worker_result(job)

        bridge, _ = make_bridge(FakeRedis(worker=prism_down))
        result = run(bridge.coordinate_request({'tenant_id': 't1', 'message': 'analyze data'}))
        assert result['status'] == 'partial'

        bridge, _ = make_bridge(FakeRedis(worker=lambda job: {'status': 'blocked', 'reason': 'Budget exceeded'}))
        result = run(bridge.coordinate_request({'tenant_id': 't1', 'message': 'hi'}))
        assert result['status'] == 'blocked'
        assert result['learning_signals']['successful_outcome'] is False

    def test_tenant_is_required(self):
        bridge, _ = make_bridge()
        with pytest.raises(ValueError):
            run(bridge.coordinate_request({'message': 'hi'}))

    def test_learning_signals_are_buffered_then_synced(self):
        posted = []

        def hermes(request):
            posted.append(json.loads(request.content))
            return httpx.Response(200, json={'ok': True})

        async def scenario():
            http = httpx.AsyncClient(base_url='http://hermes.test', transport=httpx.MockTransport(hermes))
            bridge, redis = make_bridge(http=http)
            await bridge.coordinate_request({'tenant_id': 't1', 'message': 'hi', 'conversation_id': 'c1'})
            await bridge.coordinate_request({'tenant_id': 't2', 'message': 'hey', 'conversation_id': 'c2'})
            assert len(redis.lists[bridge_mod.OUTBOUND_KEY]) == 2

            synced = await bridge.sync_learning_data()
            await http.aclose()
            return synced, redis

        synced, redis = run(scenario())
        assert synced == 2
        assert [e['tenant_id'] for e in posted[0]['entries']] == ['t1', 't2']
        assert redis.lists[bridge_mod.OUTBOUND_KEY] == []

    def test_failed_sync_keeps_the_buffer(self):
        async def scenario():
            transport = httpx.MockTransport(lambda request: httpx.Response(502))
            http = httpx.AsyncClient(base_url='http://hermes.test', transport=transport)
            bridge, redis = make_bridge(http=http)
            await bridge.coordinate_request({'tenant_id': 't1', 'message': 'hi'})
            synced = await bridge.sync_learning_data()
            await http.aclose()
            return synced, redis

        synced, redis = run(scenario())
        assert synced == 0
        assert len(redis.lists[bridge_mod.OUTBOUND_KEY]) == 1

    def test_ingested_learning_is_tagged_with_tenant(self):
        bridge, redis = make_bridge()
        count = run(bridge.ingest_learning('t1', 'communication_pattern', [{'a': 1}, {'b': 2}]))
        assert count == 2
        stored = [json.loads(x) for x in redis.lists[bridge_mod.INBOUND_KEY]]
        assert {s['tenant_id'] for s in stored} == {'t1'}
        assert stored[0]['entry'] == {'a': 1}


@pytest.fixture
def api(monkeypatch):
    from services.api.routes import hermes_integration

    monkeypatch.setenv('BACKEND_INTERNAL_KEY', 'k-test')
    bridge, redis = make_bridge()
    monkeypatch.setattr(bridge_mod, '_bridge', bridge)
    app = FastAPI()
    app.include_router(hermes_integration.router)
    return TestClient(app), redis


class TestRoutes:
    def test_app_module_imports(self):
        from services.api import main
        assert any(getattr(r, 'path', '') == '/api/hermes/coordinate' for r in main.app.routes)

    def test_coordinate_requires_internal_key(self, api):
        client, _ = api
        body = {'tenant_id': 't1', 'message': 'hi', 'channel': 'text', 'conversation_id': 'c1'}
        assert client.post('/api/hermes/coordinate', json=body).status_code == 401
        assert client.post('/api/hermes/coordinate', json=body,
                           headers={'X-Internal-Key': 'wrong'}).status_code == 401

        ok = client.post('/api/hermes/coordinate', json=body, headers={'X-Internal-Key': 'k-test'})
        assert ok.status_code == 200
        assert ok.json()['status'] == 'success'
        assert ok.json()['agents_used'] == ['atlas']

    def test_unset_key_fails_closed(self, api, monkeypatch):
        client, _ = api
        monkeypatch.delenv('BACKEND_INTERNAL_KEY')
        assert client.get('/api/hermes/metrics', headers={'X-Internal-Key': ''}).status_code == 503

    def test_tenant_is_required(self, api):
        client, _ = api
        body = {'message': 'hi', 'channel': 'text', 'conversation_id': 'c1'}
        response = client.post('/api/hermes/coordinate', json=body, headers={'X-Internal-Key': 'k-test'})
        assert response.status_code == 422

    def test_learning_sync_and_metrics(self, api):
        client, _ = api
        headers = {'X-Internal-Key': 'k-test'}
        sync = client.post('/api/hermes/learning/sync', headers=headers, json={
            'tenant_id': 't1', 'source': 'hermes', 'learning_type': 'pattern',
            'entries': [{'x': 1}], 'sync_timestamp': '2026-10-08T00:00:00Z',
        })
        assert sync.json() == {**sync.json(), 'status': 'success', 'entries_processed': 1}
        assert client.get('/api/hermes/metrics', headers=headers).json()['learning_entries_received_total'] == 1

    def test_webhook_runs_as_the_given_tenant(self, api):
        client, redis = api
        response = client.post('/api/hermes/webhook/stripe?tenant_id=t9', json={'type': 'invoice.paid'},
                               headers={'X-Internal-Key': 'k-test'})
        assert response.status_code == 200
        assert redis.jobs[-1]['tenant_id'] == 't9'
        assert redis.jobs[-1]['agent_id'] == 'nexus'

    def test_health_is_open(self, api):
        client, _ = api
        assert client.get('/api/hermes/health').json()['status'] == 'healthy'
