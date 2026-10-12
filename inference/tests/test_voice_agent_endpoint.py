"""
POST /voice/agent runs the intelligence plane's VoiceAgent inside inference.

The image copies intelligence/agents/voice_agent.py to agents/ and
intelligence/tools/ to tools/ (see inference/Dockerfile). These tests build the
same layout in a temporary directory, so they fail if either side moves.
"""

import shutil
import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

INTELLIGENCE = Path(__file__).resolve().parents[2] / "intelligence"


@pytest.fixture
def image_layout(tmp_path, monkeypatch):
    if not INTELLIGENCE.is_dir():
        pytest.skip("intelligence/ is not present in this checkout")

    (tmp_path / "agents").mkdir()
    shutil.copy(INTELLIGENCE / "agents" / "voice_agent.py", tmp_path / "agents" / "voice_agent.py")
    shutil.copytree(INTELLIGENCE / "tools", tmp_path / "tools", ignore=shutil.ignore_patterns("__pycache__"))

    monkeypatch.syspath_prepend(str(tmp_path))
    for name in list(sys.modules):
        if name.split(".")[0] in ("agents", "tools"):
            monkeypatch.delitem(sys.modules, name)


def _request(**overrides):
    body = {
        "tenant_id": "t-001",
        "agent_id": "voice_receptionist",
        "call_sid": "CA123",
        "caller_input": "Can you put me through to sales?",
        "config": {"business_name": "Acme"},
    }
    body.update(overrides)
    return body


def test_voice_agent_turn_runs_against_the_image_layout(image_layout, monkeypatch):
    import main
    from agents import voice_agent

    async def fake_llm(self, messages):
        return "Sure, one moment. [END]", 12

    monkeypatch.setattr(voice_agent.VoiceAgent, "_call_llm", fake_llm)

    res = TestClient(main.app).post("/voice/agent", json=_request())

    assert res.status_code == 200
    body = res.json()
    assert body["text"].startswith("Sure, one moment.")
    assert "end_call" in body["actions"]
    assert body["tokens_used"] == 12
    assert body["continue_conversation"] is False


def test_allowed_tools_run_through_the_shipped_registry_and_denied_ones_do_not(image_layout, monkeypatch):
    import main
    from agents import voice_agent

    from tools.registry import ToolRegistry

    calls = []

    async def fake_note(context, params):
        calls.append((context.call_sid, params["summary"]))
        return {"success": True}

    ToolRegistry.instance()._tools["record_call_note"] = fake_note

    async def fake_llm(self, messages):
        return (
            'Noted. [TOOL:record_call_note {"summary":"wants sales"}] '
            '[TOOL:transfer_call {"to_number":"+15550000000"}]'
        ), 20

    monkeypatch.setattr(voice_agent.VoiceAgent, "_call_llm", fake_llm)

    res = TestClient(main.app).post(
        "/voice/agent", json=_request(tool_allowlist=["record_call_note"])
    )

    assert res.status_code == 200
    assert calls == [("CA123", "wants sales")]
    assert "record_call_note" in res.json()["actions"]
    assert "transfer_call" not in res.json()["actions"]
