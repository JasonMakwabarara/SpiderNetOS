"""
SpiderNetOS Hermes Integration Bridge
Connects the external Hermes Agent with SpiderNetOS agent coordination.

Requests from Hermes are classified, dispatched to intelligence-plane agents
through the worker's Redis protocol (see agent_dispatch), and answered with a
combined response plus learning signals. Those signals are buffered in Redis
and shipped to Hermes on an interval; learning Hermes sends back is buffered
for SpiderNetOS training.
"""

import asyncio
import contextlib
import json
import logging
import os
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional, Tuple

import httpx

from .agent_dispatch import AgentDispatcher, agent_output, succeeded

logger = logging.getLogger(__name__)

HERMES_AGENT_URL = os.getenv('HERMES_AGENT_URL', 'http://hermes-agent:8080')
LEARNING_SYNC_INTERVAL = int(os.getenv('HERMES_LEARNING_SYNC_INTERVAL', '900'))

OUTBOUND_KEY = 'hermes:learning:outbound'
INBOUND_KEY = 'hermes:learning:inbound'
BUFFER_LIMIT = 10_000
SYNC_BATCH = 500

# (agent, intent) pairs the worker will run: the intent has to map to a
# capability the agent holds, or the MetaPlanner blocks it.
ROUTES: Dict[str, List[Tuple[str, str]]] = {
    'complex_workflow': [('nexus', 'execute_flow')],
    'multi_agent_coordination': [('prism', 'analyze_data'), ('atlas', 'chat')],
    'external_integration': [('nexus', 'execute_flow')],
    'single_agent_routing': [('atlas', 'chat')],
}


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _render(output: Any) -> str:
    if output is None:
        return ''
    if isinstance(output, str):
        return output
    if isinstance(output, dict):
        for key in ('response', 'message', 'text', 'summary'):
            if isinstance(output.get(key), str):
                return output[key]
    return json.dumps(output, default=str)


class HermesIntegrationBridge:
    def __init__(
        self,
        dispatcher: AgentDispatcher,
        redis: Any,
        hermes_url: str = HERMES_AGENT_URL,
        sync_interval: int = LEARNING_SYNC_INTERVAL,
        http_client: Optional[httpx.AsyncClient] = None,
    ):
        self.dispatcher = dispatcher
        self.redis = redis
        self.hermes_url = hermes_url
        self.sync_interval = sync_interval
        self.http = http_client
        self.learning_sync_task: Optional[asyncio.Task] = None
        self.metrics = {
            'coordination_requests_total': 0,
            'coordination_success_total': 0,
            'learning_entries_received_total': 0,
            'learning_entries_synced_total': 0,
            'total_response_time_ms': 0.0,
        }

    async def initialize(self) -> None:
        if self.http is None:
            self.http = httpx.AsyncClient(base_url=self.hermes_url, timeout=30.0)
        if self.sync_interval > 0:
            self.learning_sync_task = asyncio.create_task(self._learning_sync_loop())
        logger.info("Hermes integration bridge initialized")

    async def shutdown(self) -> None:
        if self.learning_sync_task:
            self.learning_sync_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await self.learning_sync_task
        if self.http is not None:
            await self.http.aclose()
        logger.info("Hermes integration bridge shutdown")

    # ------------------------------------------------------------------
    # Coordination
    # ------------------------------------------------------------------

    async def coordinate_request(self, hermes_request: Dict[str, Any]) -> Dict[str, Any]:
        tenant_id = hermes_request.get('tenant_id')
        if not tenant_id:
            raise ValueError('tenant_id is required')

        loop = asyncio.get_running_loop()
        started = loop.time()
        self.metrics['coordination_requests_total'] += 1

        message = hermes_request.get('message', '')
        intent_analysis = self.analyze_intent(message, hermes_request)
        routes = ROUTES[intent_analysis['type']]

        results = await asyncio.gather(*(
            self.dispatcher.dispatch(
                tenant_id=tenant_id,
                agent_id=agent_id,
                intent=intent,
                message=message,
                session_id=hermes_request.get('conversation_id', ''),
                ast={'type': intent, 'hermes_intent': intent_analysis['type']},
                metadata={
                    'coordinated_by': 'hermes',
                    'channel': hermes_request.get('channel', 'unknown'),
                    'context_variables': intent_analysis['context_variables'],
                    'request': hermes_request.get('metadata') or {},
                },
            )
            for agent_id, intent in routes
        ))

        result = self._combine(routes, results)
        result['execution_time_ms'] = (loop.time() - started) * 1000
        self.metrics['total_response_time_ms'] += result['execution_time_ms']
        if result['status'] == 'success':
            self.metrics['coordination_success_total'] += 1

        result['learning_signals'] = self._learning_signals(hermes_request, result, intent_analysis)
        try:
            await self._buffer(OUTBOUND_KEY, [result['learning_signals']])
        except Exception as e:
            logger.warning("Could not buffer Hermes learning signals: %s", e)

        return result

    def analyze_intent(self, message: str, request: Dict[str, Any]) -> Dict[str, Any]:
        """Keyword classification, unless the caller already classified it."""
        given = (request.get('intent_analysis') or {}).get('type')
        if given in ROUTES:
            intent_type = given
        else:
            text = message.lower()
            if any(w in text for w in ('workflow', 'process', 'orchestrate', 'coordinate')):
                intent_type = 'complex_workflow'
            elif any(w in text for w in ('analyze', 'data', 'research', 'find')):
                intent_type = 'multi_agent_coordination'
            elif any(w in text for w in ('integrate', 'connect', 'api', 'webhook')):
                intent_type = 'external_integration'
            else:
                intent_type = 'single_agent_routing'

        return {
            'type': intent_type,
            'required_agents': [agent for agent, _ in ROUTES[intent_type]],
            'context_variables': self._context_variables(message, request.get('conversation_history') or []),
        }

    @staticmethod
    def _context_variables(message: str, history: List[Dict]) -> Dict[str, Any]:
        text = message.lower()
        variables: Dict[str, Any] = {
            'urgency': any(w in text for w in ('urgent', 'asap', 'emergency', 'critical', 'immediately')),
            'complexity': any(w in text for w in ('complex', 'multiple', 'coordinate', 'orchestrate', 'workflow')),
        }
        channels = {i['channel'] for i in history[-5:] if isinstance(i, dict) and 'channel' in i}
        if channels:
            variables['preferred_channels'] = sorted(channels)
        return variables

    @staticmethod
    def _combine(routes: List[Tuple[str, str]], results: List[Dict[str, Any]]) -> Dict[str, Any]:
        agents = [agent for agent, _ in routes]
        ok = [(agent, r) for agent, r in zip(agents, results, strict=True) if succeeded(r)]

        if len(ok) == len(results):
            status = 'success'
        elif ok:
            status = 'partial'
        else:
            status = results[0].get('status', 'error') if results else 'error'
            if status == 'success':
                status = 'error'

        if not ok:
            response = "The SpiderNetOS agents could not complete this request."
        elif len(ok) == 1:
            response = _render(agent_output(ok[0][1])) or 'Request processed.'
        else:
            response = "\n".join(
                f"{agent.title()}: {_render(agent_output(r)) or 'done'}" for agent, r in ok
            )

        workflow_triggers = []
        for agent, r in ok:
            output = agent_output(r)
            if agent == 'nexus' and isinstance(output, dict) and output.get('workflow_id'):
                workflow_triggers.append(str(output['workflow_id']))

        return {
            'status': status,
            'response': response,
            'agents_used': agents,
            'workflow_triggers': workflow_triggers,
            'individual_results': [
                {'agent': agent, 'status': r.get('status'), 'reason': r.get('reason')}
                for agent, r in zip(agents, results, strict=True)
            ],
        }

    @staticmethod
    def _learning_signals(request: Dict, result: Dict, intent_analysis: Dict) -> Dict[str, Any]:
        agents = result.get('agents_used', [])
        succeeded_all = result.get('status') == 'success'
        quality = (0.9 if len(agents) > 1 else 0.7) if succeeded_all else 0.2
        return {
            'tenant_id': request.get('tenant_id'),
            'conversation_id': request.get('conversation_id', ''),
            'channel': request.get('channel', 'unknown'),
            'intent_type': intent_analysis.get('type', 'unknown'),
            'agents_coordinated': agents,
            'successful_outcome': result.get('status') == 'success',
            'response_quality': quality,
            'coordination_complexity': len(agents),
            'user_satisfaction': request.get('user_satisfaction', 0.5),
            'timestamp': _now(),
        }

    # ------------------------------------------------------------------
    # Learning exchange
    # ------------------------------------------------------------------

    async def _buffer(self, key: str, entries: List[Dict[str, Any]]) -> None:
        if not entries:
            return
        await self.redis.rpush(key, *(json.dumps(e, default=str) for e in entries))
        await self.redis.ltrim(key, -BUFFER_LIMIT, -1)

    async def ingest_learning(
        self,
        tenant_id: str,
        learning_type: str,
        entries: List[Dict[str, Any]],
        source: str = 'hermes',
    ) -> int:
        """Buffer learning data sent by Hermes for SpiderNetOS training."""
        if not tenant_id:
            raise ValueError('tenant_id is required')
        received_at = _now()
        await self._buffer(INBOUND_KEY, [
            {
                'tenant_id': tenant_id,
                'source': source,
                'learning_type': learning_type,
                'received_at': received_at,
                'entry': entry,
            }
            for entry in entries
        ])
        self.metrics['learning_entries_received_total'] += len(entries)
        return len(entries)

    async def sync_learning_data(self) -> int:
        """Send buffered learning signals to Hermes. Entries leave the buffer
        only once Hermes accepts them."""
        raw = await self.redis.lrange(OUTBOUND_KEY, 0, SYNC_BATCH - 1)
        if not raw:
            return 0

        entries = [json.loads(item) for item in raw]
        response = await self.http.post('/api/learning/sync', json={
            'source': 'spidernet',
            'learning_type': 'communication_pattern',
            'entries': entries,
            'sync_timestamp': _now(),
        })
        if response.status_code >= 300:
            logger.error("Hermes learning sync failed: HTTP %s", response.status_code)
            return 0

        await self.redis.ltrim(OUTBOUND_KEY, len(raw), -1)
        self.metrics['learning_entries_synced_total'] += len(raw)
        logger.info("Synced %d learning entries with Hermes", len(raw))
        return len(raw)

    async def _learning_sync_loop(self) -> None:
        while True:
            try:
                await self.sync_learning_data()
            except asyncio.CancelledError:
                raise
            except Exception as e:
                logger.error("Learning sync error: %s", e)
            await asyncio.sleep(self.sync_interval)

    def snapshot_metrics(self) -> Dict[str, Any]:
        total = self.metrics['coordination_requests_total']
        return {
            'coordination_requests_total': total,
            'learning_entries_received_total': self.metrics['learning_entries_received_total'],
            'learning_entries_synced_total': self.metrics['learning_entries_synced_total'],
            'average_response_time_ms': self.metrics['total_response_time_ms'] / total if total else 0.0,
            'coordination_success_rate': self.metrics['coordination_success_total'] / total if total else 1.0,
        }


_bridge: Optional[HermesIntegrationBridge] = None


async def start_hermes_bridge(redis_url: Optional[str] = None) -> HermesIntegrationBridge:
    """Build the process-wide bridge from the environment."""
    global _bridge
    if _bridge is None:
        import redis.asyncio as aioredis

        redis = aioredis.from_url(redis_url or os.getenv('REDIS_URL', 'redis://localhost:6379/0'))
        dispatcher = AgentDispatcher(redis, timeout=float(os.getenv('HERMES_DISPATCH_TIMEOUT', '30')))
        _bridge = HermesIntegrationBridge(dispatcher, redis)
        await _bridge.initialize()
    return _bridge


async def stop_hermes_bridge() -> None:
    global _bridge
    if _bridge is not None:
        await _bridge.shutdown()
        close = getattr(_bridge.redis, 'aclose', None) or _bridge.redis.close
        await close()
        _bridge = None


def current_bridge() -> Optional[HermesIntegrationBridge]:
    return _bridge
