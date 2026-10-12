"""
SpiderNet OS - Agent Dispatch Client
Request/response over the intelligence worker's Redis protocol.

The worker (intelligence/main.py) BLPOPs JSON jobs from the `agent:dispatch`
list, runs them through its MetaPlanner, and publishes
`{event_id, result, timestamp}` on `agent:result:{tenant_id}`. Pub/sub does
not buffer, so the result channel is subscribed before the job is queued.
"""

import asyncio
import json
import uuid
from typing import Any, Dict, Optional

DISPATCH_QUEUE = 'agent:dispatch'


def result_channel(tenant_id: str) -> str:
    return f'agent:result:{tenant_id}'


class AgentDispatcher:
    def __init__(self, redis: Any, timeout: float = 30.0, queue: str = DISPATCH_QUEUE):
        self.redis = redis
        self.timeout = timeout
        self.queue = queue

    async def dispatch(
        self,
        tenant_id: str,
        agent_id: str,
        intent: str,
        message: str,
        *,
        session_id: str = '',
        user_id: Optional[str] = None,
        ast: Optional[Dict[str, Any]] = None,
        metadata: Optional[Dict[str, Any]] = None,
        flow_id: Optional[str] = None,
        timeout: Optional[float] = None,
    ) -> Dict[str, Any]:
        """
        Queue one job and wait for its result. Returns the MetaPlanner's
        result dict (`status` is success, error or blocked), or
        `{'status': 'timeout'}` if the worker does not answer in time.
        """
        if not tenant_id:
            raise ValueError('tenant_id is required')

        event_id = uuid.uuid4().hex
        job = {
            'event_id': event_id,
            'tenant_id': tenant_id,
            'agent_id': agent_id,
            'intent': intent,
            'flow_id': flow_id,
            'context': {
                'session_id': session_id,
                'user_id': user_id,
                'original_message': message,
                'ast': ast or {'type': intent},
                'metadata': metadata or {},
            },
        }

        pubsub = self.redis.pubsub()
        await pubsub.subscribe(result_channel(tenant_id))
        try:
            await self.redis.rpush(self.queue, json.dumps(job))
            return await self._await_result(pubsub, event_id, timeout or self.timeout)
        finally:
            await pubsub.unsubscribe(result_channel(tenant_id))
            close = getattr(pubsub, 'aclose', None) or pubsub.close
            await close()

    async def _await_result(self, pubsub: Any, event_id: str, timeout: float) -> Dict[str, Any]:
        loop = asyncio.get_running_loop()
        deadline = loop.time() + timeout
        while True:
            remaining = deadline - loop.time()
            if remaining <= 0:
                return {'status': 'timeout', 'reason': f'no result within {timeout:g}s'}

            message = await pubsub.get_message(
                ignore_subscribe_messages=True, timeout=min(remaining, 1.0)
            )
            if not message or message.get('type') != 'message':
                continue

            try:
                data = json.loads(message['data'])
            except (TypeError, ValueError):
                continue
            if data.get('event_id') != event_id:
                continue

            result = data.get('result')
            if not isinstance(result, dict):
                return {'status': 'error', 'reason': 'worker returned no result'}
            return result


def succeeded(result: Dict[str, Any]) -> bool:
    """The MetaPlanner ran the agent and the agent itself reported success."""
    if result.get('status') != 'success':
        return False
    inner = result.get('result')
    return not isinstance(inner, dict) or inner.get('status', 'success') == 'success'


def agent_output(result: Dict[str, Any]) -> Any:
    """The agent's own output from a successful dispatch result, else None."""
    if not succeeded(result):
        return None
    inner = result.get('result')
    return inner.get('output') if isinstance(inner, dict) else None
