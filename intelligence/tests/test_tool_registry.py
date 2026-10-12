"""
ToolRegistry defaults.

The registry is imported as `tools.registry` in the intelligence plane and in
the inference image, which copies intelligence/tools/ in. Its own imports are
package-relative so they resolve the same way in both.
"""

import asyncio

from tools.registry import ToolRegistry


def test_instance_registers_core_voice_and_sales_tools():
    ToolRegistry._instance = None
    registry = ToolRegistry.instance()

    for tool_id in ("memory_search", "transfer_call", "end_call", "score_lead"):
        assert registry.has_tool(tool_id)


def test_allowlist_denies_unlisted_tools():
    ToolRegistry._instance = None
    registry = ToolRegistry.instance()

    class Ctx:
        tenant_id = "t-001"
        tool_allowlist = ["end_call"]

    loop = asyncio.new_event_loop()
    try:
        result = loop.run_until_complete(registry.execute("transfer_call", Ctx(), {}))
    finally:
        loop.close()

    assert result["success"] is False
    assert result["code"] == "TOOL_DENIED"
