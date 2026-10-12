"""The worker publishes every dispatch result on agent:result:{tenant}.

A successful dispatch returns an AgentResult dataclass inside the result, and
json.dumps raised on it, so only failures were ever published and anything
waiting on a success timed out.
"""

import json

import main
from core.agent_base import AgentResult


def test_success_result_with_agent_result_encodes():
    payload = {
        'event_id': 'e1',
        'result': {
            'status': 'success',
            'result': AgentResult(status='success', output={'reply': 'hi'}, tokens_used=7),
            'execution_time_ms': 3.2,
        },
        'timestamp': '2026-10-08T00:00:00+00:00',
    }

    decoded = json.loads(main.encode_result(payload))

    assert decoded['event_id'] == 'e1'
    assert decoded['result']['result']['output'] == {'reply': 'hi'}
    assert decoded['result']['result']['tokens_used'] == 7


def test_unknown_objects_degrade_to_strings():
    decoded = json.loads(main.encode_result({'result': {'odd': object}}))
    assert isinstance(decoded['result']['odd'], str)
