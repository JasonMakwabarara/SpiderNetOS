"""
ToolRegistry - Dynamic tool registry for SpiderNet OS v3.2 agents.

Provides a central registry of tools that dynamic agents can access.
Tools that delegate to agents do so through meta_planner.dispatch().
"""

from __future__ import annotations

import logging
from typing import Any, Callable, Coroutine, Dict, Optional

logger = logging.getLogger(__name__)

# Type alias for tool handler functions
ToolHandler = Callable[..., Coroutine[Any, Any, Dict[str, Any]]]


class ToolRegistry:
    """
    Singleton registry for tools that dynamic agents can invoke.
    Each tool is an async function(context, params) -> dict.
    """

    _instance: Optional["ToolRegistry"] = None
    _meta_planner: Any = None

    def __init__(self):
        self._tools: Dict[str, ToolHandler] = {}

    @classmethod
    def instance(cls) -> "ToolRegistry":
        """Return the singleton ToolRegistry instance."""
        if cls._instance is None:
            cls._instance = cls()
            cls._instance._register_defaults()
        return cls._instance

    @classmethod
    def configure(cls, meta_planner: Any) -> None:
        """Configure the registry with the meta planner for delegation tools."""
        cls._meta_planner = meta_planner

    def register(self, tool_id: str, handler: ToolHandler) -> None:
        """Register a tool handler by its ID."""
        if tool_id in self._tools:
            logger.warning("Overwriting existing tool registration: %s", tool_id)
        self._tools[tool_id] = handler
        logger.info("Registered tool: %s", tool_id)

    async def execute(
        self,
        tool_id: str,
        context: Any,
        params: Dict[str, Any],
    ) -> Dict[str, Any]:
        """
        Execute a registered tool by ID.

        Phase B additions:
          - Enforces context.tool_allowlist if present (list of permitted tool IDs).
            If context.tool_allowlist is None the original permit-all behaviour is preserved.
          - Emits tool.invoked / tool.completed / tool.denied Redis events via context.event_emitter
            if available.

        Args:
            tool_id: The tool identifier.
            context: The agent context (AgentContext or VoiceContext or any object that may
                     carry .tool_allowlist and .event_emitter attributes).
            params: Parameters to pass to the tool handler.

        Returns:
            Tool execution result dict.

        Raises:
            KeyError: If the tool_id is not registered.
        """
        if tool_id not in self._tools:
            raise KeyError(f"Tool '{tool_id}' is not registered in the registry.")

        # Phase B: per-agent / per-call allow-list enforcement
        tool_allowlist = getattr(context, "tool_allowlist", None)
        if tool_allowlist is not None and tool_id not in tool_allowlist:
            logger.warning("Tool '%s' denied by allow-list for context %s", tool_id, getattr(context, "tenant_id", "?"))
            self._emit_event(context, "tool.denied", {"tool_id": tool_id, "reason": "not_in_allowlist"})
            return {
                "success": False,
                "error": f"Tool '{tool_id}' is not permitted for this agent.",
                "code": "TOOL_DENIED",
            }

        handler = self._tools[tool_id]
        logger.debug("Executing tool: %s with params: %s", tool_id, params)
        self._emit_event(context, "tool.invoked", {"tool_id": tool_id})

        try:
            result = await handler(context, params)
            success = result.get("success", True)
            self._emit_event(context, "tool.completed", {"tool_id": tool_id, "success": success})
            return result
        except Exception as e:
            logger.exception("Tool '%s' execution failed: %s", tool_id, str(e))
            self._emit_event(context, "tool.completed", {"tool_id": tool_id, "success": False, "error": str(e)})
            raise

    # ─── Event emitter helper ─────────────────────────────────────────────────

    def _emit_event(self, context: Any, event_type: str, payload: Dict[str, Any]) -> None:
        """Emit a tool lifecycle event via context.event_emitter if available."""
        emitter = getattr(context, "event_emitter", None)
        if emitter is None:
            return
        try:
            import asyncio
            coro = emitter(event_type, {
                **payload,
                "tenant_id": getattr(context, "tenant_id", None),
                "call_sid":  getattr(context, "call_sid", None),
            })
            # Fire-and-forget if we're inside an event loop
            if asyncio.get_event_loop().is_running():
                asyncio.ensure_future(coro)
        except Exception as e:
            logger.debug("Event emission failed: %s", e)

    def list_tools(self) -> list[str]:
        """Return a list of all registered tool IDs."""
        return list(self._tools.keys())

    def has_tool(self, tool_id: str) -> bool:
        """Check if a tool is registered."""
        return tool_id in self._tools

    def _register_defaults(self) -> None:
        """Register all 9 default tools + 6 voice tools."""
        # Core tools
        self.register("memory_search", _tool_memory_search)
        self.register("flow_execution", _tool_flow_execution)
        self.register("data_analysis", _tool_data_analysis)
        self.register("system_status", _tool_system_status)
        self.register("create_flow", _tool_create_flow)
        self.register("file_read", _tool_file_read)
        self.register("file_write", _tool_file_write)
        self.register("web_search", _tool_web_search)
        self.register("document_parse", _tool_document_parse)

        # Voice AI tools
        from voice_tools import VOICE_TOOLS
        for tool_id, handler in VOICE_TOOLS.items():
            self.register(tool_id, handler)


# ---------------------------------------------------------------------------
# Default tool implementations
# ---------------------------------------------------------------------------


async def _tool_memory_search(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """Search the memory graph for relevant information."""
    query = params.get("query", "")
    limit = params.get("limit", 10)
    agent_id = params.get("agent_id")

    # Access memory graph from context if available
    memory_graph = getattr(context, "memory_graph", None)
    if memory_graph is None:
        return {"results": [], "message": "Memory graph not available."}

    try:
        results = await memory_graph.search(
            query=query,
            agent_id=agent_id,
            limit=limit,
        )
        return {
            "results": results or [],
            "count": len(results) if results else 0,
            "query": query,
        }
    except Exception as e:
        logger.error("memory_search failed: %s", str(e))
        return {"results": [], "error": str(e)}


async def _tool_flow_execution(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Execute a flow by delegating to the appropriate agent through meta_planner.
    """
    meta_planner = ToolRegistry._meta_planner
    if meta_planner is None:
        return {"success": False, "error": "Meta planner not configured."}

    flow_id = params.get("flow_id")
    flow_input = params.get("input", {})

    try:
        result = await meta_planner.dispatch(
            target_agent="nexus",
            context=context,
            source_agent="tool:flow_execution",
            payload={"flow_id": flow_id, "input": flow_input},
        )
        return {
            "success": result.get("success", False),
            "output": result.get("output", ""),
            "flow_id": flow_id,
        }
    except Exception as e:
        logger.error("flow_execution failed: %s", str(e))
        return {"success": False, "error": str(e)}


async def _tool_data_analysis(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Analyze data by delegating to the prism agent through meta_planner.
    """
    meta_planner = ToolRegistry._meta_planner
    if meta_planner is None:
        return {"success": False, "error": "Meta planner not configured."}

    dataset = params.get("dataset")
    analysis_type = params.get("type", "summary")
    query = params.get("query", "")

    try:
        result = await meta_planner.dispatch(
            target_agent="prism",
            context=context,
            source_agent="tool:data_analysis",
            payload={
                "dataset": dataset,
                "type": analysis_type,
                "query": query,
            },
        )
        return {
            "success": result.get("success", False),
            "analysis": result.get("output", ""),
            "type": analysis_type,
        }
    except Exception as e:
        logger.error("data_analysis failed: %s", str(e))
        return {"success": False, "error": str(e)}


async def _tool_system_status(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """Query system status, health, and metrics."""
    component = params.get("component", "all")

    # Retrieve status from context or a status service
    status_service = getattr(context, "status_service", None)
    if status_service:
        try:
            status = await status_service.get_status(component=component)
            return {"success": True, "status": status, "component": component}
        except Exception as e:
            logger.error("system_status failed: %s", str(e))
            return {"success": False, "error": str(e)}

    return {
        "success": True,
        "status": {
            "system": "operational",
            "component": component,
            "message": "Status service not available; returning default status.",
        },
    }


async def _tool_create_flow(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Create a new flow by delegating to the forge agent through meta_planner.
    """
    meta_planner = ToolRegistry._meta_planner
    if meta_planner is None:
        return {"success": False, "error": "Meta planner not configured."}

    flow_name = params.get("name", "Untitled Flow")
    flow_definition = params.get("definition", {})
    flow_description = params.get("description", "")

    try:
        result = await meta_planner.dispatch(
            target_agent="forge",
            context=context,
            source_agent="tool:create_flow",
            payload={
                "name": flow_name,
                "definition": flow_definition,
                "description": flow_description,
            },
        )
        return {
            "success": result.get("success", False),
            "flow_id": result.get("flow_id"),
            "output": result.get("output", ""),
        }
    except Exception as e:
        logger.error("create_flow failed: %s", str(e))
        return {"success": False, "error": str(e)}


async def _tool_file_read(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """Read a file from the allowed file system paths."""
    file_path = params.get("path", "")
    encoding = params.get("encoding", "utf-8")

    if not file_path:
        return {"success": False, "error": "No file path provided."}

    # Security: validate path is within allowed directories
    import os

    allowed_base = os.environ.get("SPIDERNET_FILES_ROOT", "/data/files")
    abs_path = os.path.abspath(file_path)
    if not abs_path.startswith(os.path.abspath(allowed_base)):
        return {
            "success": False,
            "error": f"Access denied: path must be within {allowed_base}",
        }

    try:
        with open(abs_path, "r", encoding=encoding) as f:
            content = f.read()
        return {
            "success": True,
            "content": content,
            "path": abs_path,
            "size": len(content),
        }
    except FileNotFoundError:
        return {"success": False, "error": f"File not found: {abs_path}"}
    except Exception as e:
        logger.error("file_read failed: %s", str(e))
        return {"success": False, "error": str(e)}


async def _tool_file_write(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """Write content to a file within allowed file system paths."""
    file_path = params.get("path", "")
    content = params.get("content", "")
    encoding = params.get("encoding", "utf-8")

    if not file_path:
        return {"success": False, "error": "No file path provided."}

    import os

    allowed_base = os.environ.get("SPIDERNET_FILES_ROOT", "/data/files")
    abs_path = os.path.abspath(file_path)
    if not abs_path.startswith(os.path.abspath(allowed_base)):
        return {
            "success": False,
            "error": f"Access denied: path must be within {allowed_base}",
        }

    try:
        os.makedirs(os.path.dirname(abs_path), exist_ok=True)
        with open(abs_path, "w", encoding=encoding) as f:
            f.write(content)
        return {
            "success": True,
            "path": abs_path,
            "bytes_written": len(content.encode(encoding)),
        }
    except Exception as e:
        logger.error("file_write failed: %s", str(e))
        return {"success": False, "error": str(e)}


async def _tool_web_search(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """Perform a web search query."""
    query = params.get("query", "")
    max_results = params.get("max_results", 5)

    if not query:
        return {"success": False, "error": "No search query provided."}

    # Delegate to a search service if available
    search_service = getattr(context, "search_service", None)
    if search_service:
        try:
            results = await search_service.search(query=query, limit=max_results)
            return {
                "success": True,
                "results": results,
                "query": query,
                "count": len(results),
            }
        except Exception as e:
            logger.error("web_search failed: %s", str(e))
            return {"success": False, "error": str(e)}

    return {
        "success": False,
        "error": "Web search service not configured.",
        "query": query,
    }


async def _tool_document_parse(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """Parse and extract content from a document."""
    source = params.get("source", "")
    format_hint = params.get("format", "auto")

    if not source:
        return {"success": False, "error": "No document source provided."}

    # Delegate to document parser service if available
    parser_service = getattr(context, "parser_service", None)
    if parser_service:
        try:
            parsed = await parser_service.parse(source=source, format_hint=format_hint)
            return {
                "success": True,
                "content": parsed.get("content", ""),
                "metadata": parsed.get("metadata", {}),
                "source": source,
            }
        except Exception as e:
            logger.error("document_parse failed: %s", str(e))
            return {"success": False, "error": str(e)}

    return {
        "success": False,
        "error": "Document parser service not configured.",
        "source": source,
    }
