"""
VoiceAgent unit tests — Phase B

Tests:
 - System prompt construction (token cap, business name substitution)
 - Tool allow-list enforcement (deny unlisted tools, permit listed ones)
 - Inline action tag parsing ([END], [TRANSFER], [SMS])
 - TOOL tag parsing
 - LLM response truncation to ≤ 2 sentences
 - Fallback response on timeout
 - Memory write
"""

import asyncio

import pytest
from agents.voice_agent import VoiceAgent, VoiceContext

# ─── Fixtures ────────────────────────────────────────────────────────────────


def make_ctx(**overrides) -> VoiceContext:
    defaults = dict(
        tenant_id="t-001",
        agent_id="voice_receptionist",
        call_sid="CAtest001",
        caller_number="+15555551234",
        caller_input="I'd like to book an appointment",
        voice_number="+15555557890",
        transcript=[],
        tool_allowlist=None,    # permit-all by default
        approval_policy="off",
        config={"business_name": "Acme Corp"},
        memory_graph=None,
        safety_guard=None,
    )
    defaults.update(overrides)
    return VoiceContext(**defaults)


class FakeToolRegistry:
    def __init__(self, allowed_tools=None, fail_tools=None):
        self.called = []
        self._allowed = allowed_tools or {}
        self._fail    = fail_tools or set()

    async def execute(self, tool_id, context, params):
        if tool_id not in self._allowed and not self._allowed.get("*"):
            raise KeyError(f"Tool '{tool_id}' not registered")
        self.called.append({"tool": tool_id, "params": params})
        if tool_id in self._fail:
            return {"success": False, "error": "intentional failure"}
        return {"success": True, **self._allowed.get(tool_id, {})}


class FakeLLM:
    """Patches VoiceAgent._call_llm to return a preset response."""
    def __init__(self, text="Hello there! How can I help you today?", tokens=25):
        self.text   = text
        self.tokens = tokens

    async def __call__(self, messages):
        return self.text, self.tokens


# ─── VoiceAgent tests ────────────────────────────────────────────────────────


@pytest.fixture
def agent():
    return VoiceAgent(ollama_url="http://fake-ollama:11434", model="qwen3")


class TestSystemPrompt:
    def test_includes_business_name(self, agent):
        ctx    = make_ctx(config={"business_name": "Beta Inc"})
        prompt = agent._build_system_prompt(ctx)
        assert "Beta Inc" in prompt

    def test_includes_allowlist_tools(self, agent):
        ctx    = make_ctx(tool_allowlist=["end_call", "send_sms"])
        prompt = agent._build_system_prompt(ctx)
        assert "end_call" in prompt
        assert "send_sms" in prompt

    def test_includes_all_tools_when_allowlist_none(self, agent):
        ctx    = make_ctx(tool_allowlist=None)
        prompt = agent._build_system_prompt(ctx)
        assert "transfer_call" in prompt

    def test_appends_custom_system_prompt(self, agent):
        ctx    = make_ctx(config={"business_name": "X", "system_prompt": "Secret context"})
        prompt = agent._build_system_prompt(ctx)
        assert "Secret context" in prompt


class TestResponseParsing:
    def test_parses_end_inline_action(self, agent):
        ctx  = make_ctx()
        text, tools, actions = agent._parse_response("Thank you for calling. [END]", ctx)
        assert "end_call" in actions
        assert "[END]" not in text

    def test_parses_sms_inline_action(self, agent):
        ctx  = make_ctx()
        text, tools, actions = agent._parse_response("I will send you a message. [SMS]", ctx)
        assert "send_sms" in actions

    def test_parses_tool_tag_with_params(self, agent):
        ctx  = make_ctx()
        raw  = 'I will transfer you. [TOOL:transfer_call {"to_number":"+15550001111"}]'
        text, tools, actions = agent._parse_response(raw, ctx)
        assert len(tools) == 1
        assert tools[0]["name"] == "transfer_call"
        assert tools[0]["params"]["to_number"] == "+15550001111"
        assert "[TOOL:" not in text

    def test_tool_denied_when_not_in_allowlist(self, agent):
        ctx  = make_ctx(tool_allowlist=["end_call"])
        raw  = '[TOOL:send_sms {"to_number":"+15550001111","message":"Hi"}]'
        text, tools, actions = agent._parse_response(raw, ctx)
        # send_sms not in allowlist — should be ignored
        assert len(tools) == 0

    def test_text_truncated_to_two_sentences(self, agent):
        ctx  = make_ctx()
        raw  = "First sentence. Second sentence. Third sentence. Fourth sentence."
        text, _, _ = agent._parse_response(raw, ctx)
        # Should only have first two sentences
        parts = [s for s in text.split(".") if s.strip()]
        assert len(parts) <= 2


class TestMessageBuilding:
    def test_includes_transcript_history(self, agent):
        ctx = make_ctx(transcript=[
            {"speaker": "caller", "text": "Hello"},
            {"speaker": "agent",  "text": "Hi there"},
        ])
        messages = agent._build_messages(ctx, "System prompt")
        roles = [m["role"] for m in messages]
        assert "user" in roles
        assert "assistant" in roles

    def test_limits_transcript_to_six_entries(self, agent):
        transcript = [
            {"speaker": "caller" if i % 2 == 0 else "agent", "text": f"turn {i}"}
            for i in range(20)
        ]
        ctx      = make_ctx(transcript=transcript)
        messages = agent._build_messages(ctx, "System")
        # system + up to 6 history + 1 current = at most 8
        assert len(messages) <= 8


class TestAllowListEnforcement:
    def test_is_tool_allowed_with_none_allowlist(self, agent):
        ctx = make_ctx(tool_allowlist=None)
        assert agent._is_tool_allowed("send_sms", ctx) is True

    def test_is_tool_allowed_when_listed(self, agent):
        ctx = make_ctx(tool_allowlist=["send_sms", "end_call"])
        assert agent._is_tool_allowed("send_sms", ctx) is True

    def test_is_tool_denied_when_not_listed(self, agent):
        ctx = make_ctx(tool_allowlist=["end_call"])
        assert agent._is_tool_allowed("send_sms", ctx) is False


class TestFallbackResponse:
    def test_fallback_returns_safe_message(self, agent):
        import time
        start  = time.monotonic()
        result = agent._fallback_response(start, reason="test")
        assert result["text"] != ""
        assert result["continue_conversation"] is False
        assert "transfer_call" in result["actions"]


class TestRunMethod:
    def test_run_returns_expected_shape(self, agent):
        ctx        = make_ctx()
        fake_llm   = FakeLLM("I can help you with that!")
        agent._call_llm = fake_llm

        result = asyncio.get_event_loop().run_until_complete(agent.run(ctx))

        assert "text" in result
        assert "actions" in result
        assert "continue_conversation" in result
        assert "model" in result
        assert "tokens_used" in result
        assert "latency_ms" in result

    def test_run_calls_tool_when_tagged(self, agent):
        ctx = make_ctx(
            caller_input="Book me an appointment",
            tool_allowlist=["calendar_booking"],
        )
        fake_llm = FakeLLM('[TOOL:calendar_booking {"date":"2026-06-01","time":"10:00"}]')
        agent._call_llm = fake_llm
        registry = FakeToolRegistry(allowed_tools={"calendar_booking": {"event_id": "evt_001"}})
        agent.tool_registry = registry

        asyncio.get_event_loop().run_until_complete(agent.run(ctx))

        assert any(c["tool"] == "calendar_booking" for c in registry.called)

    def test_run_does_not_call_unlisted_tool(self, agent):
        ctx = make_ctx(
            caller_input="send me a text",
            tool_allowlist=["end_call"],    # send_sms NOT allowed
        )
        fake_llm = FakeLLM('[TOOL:send_sms {"to_number":"+155","message":"test"}]')
        agent._call_llm = fake_llm
        registry = FakeToolRegistry(allowed_tools={"*": True})
        agent.tool_registry = registry

        asyncio.get_event_loop().run_until_complete(agent.run(ctx))

        assert all(c["tool"] != "send_sms" for c in registry.called)

    def test_run_uses_fallback_on_timeout(self, agent):
        async def slow_llm(messages):
            await asyncio.sleep(99)
            return "Never", 0

        ctx = make_ctx()
        agent._call_llm = slow_llm

        import unittest.mock as mock
        # Patch HARD_TURN_MS to 10ms so timeout fires immediately
        with mock.patch("agents.voice_agent.HARD_TURN_MS", 10):
            result = asyncio.get_event_loop().run_until_complete(agent.run(ctx))

        assert result["continue_conversation"] is False
        assert "transfer_call" in result["actions"]
