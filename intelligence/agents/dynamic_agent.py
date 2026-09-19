"""
DynamicAgent - Config-driven generic agent executor for SpiderNet OS v3.2

Reads behavior from DB config JSONB, enabling dynamic agent creation
without code changes. Supports delegation, tool execution, and memory retrieval.
"""

from __future__ import annotations

import logging
from typing import Any, Dict, List

from core.agent_base import AgentBase, AgentContext, AgentResult

logger = logging.getLogger(__name__)


class DynamicAgent(AgentBase):
    """
    A config-driven generic agent that reads its behavior from a JSONB config
    stored in the database. Supports LLM completion, tool execution, memory
    retrieval, and delegation through the meta planner.
    """

    def __init__(
        self,
        agent_id: str,
        name: str,
        capabilities: List[str],
        config: Dict[str, Any],
        meta_planner: Any,
        cost_governor: Any,
        memory_graph: Any,
        llm_client: Any,
    ):
        super().__init__(agent_id=agent_id, name=name)
        self.agent_id = agent_id
        self.name = name
        self.capabilities = capabilities
        self.config = config
        self.meta_planner = meta_planner
        self.cost_governor = cost_governor
        self.memory_graph = memory_graph
        self.llm_client = llm_client

        # Read behavior from config JSONB
        self.system_prompt: str = config.get("system_prompt", f"You are {name}, an AI agent.")
        self.model: str = config.get("model", "gemma3:9b")
        self.tools: List[str] = config.get("tools", [])
        self.delegation: List[str] = config.get("delegation", [])
        self.temperature: float = config.get("temperature", 0.7)
        self.max_tokens: int = config.get("max_tokens", 4096)

    async def execute(self, context: AgentContext) -> AgentResult:
        """
        Main execution method for the dynamic agent.

        Steps:
        1. Retrieve relevant memory from memory_graph
        2. Check if intent should be delegated
        3. Build prompt with system_prompt + memory context + user message
        4. Call llm_client.complete() with configured model
        5. Execute tool calls if response contains them
        6. Return AgentResult
        """
        try:
            # Step 1: Retrieve relevant memory
            memories = await self._retrieve_memories(context)

            # Step 2: Check delegation — Hard Rule #2: delegate through meta_planner.dispatch() ONLY
            if hasattr(context, "ast") and context.ast is not None:
                delegate_to = getattr(context.ast, "delegate_to", None)
                if delegate_to and delegate_to in self.delegation:
                    logger.info(
                        "Agent %s delegating to %s via meta_planner",
                        self.agent_id,
                        delegate_to,
                    )
                    return await self._delegate(context)

            # Step 3: Build prompt
            prompt = self._build_prompt(context, memories)

            # Step 4: Check cost budget before LLM call
            cost_estimate = self.cost_governor.estimate(
                model=self.model,
                input_tokens=len(prompt) // 4,  # rough estimate
                max_output_tokens=self.max_tokens,
            )
            if not self.cost_governor.approve(cost_estimate, context):
                logger.warning(
                    "Agent %s: Cost governor rejected request (estimate: %s)",
                    self.agent_id,
                    cost_estimate,
                )
                return AgentResult(
                    agent_id=self.agent_id,
                    success=False,
                    output="Request exceeds cost budget. Please try a simpler query.",
                    metadata={"reason": "cost_limit_exceeded", "estimate": cost_estimate},
                )

            # Step 4: Call LLM
            response = await self.llm_client.complete(
                model=self.model,
                messages=[
                    {"role": "system", "content": self.system_prompt},
                    {"role": "user", "content": prompt},
                ],
                temperature=self.temperature,
                max_tokens=self.max_tokens,
            )

            # Step 5: Execute tool calls if present
            tool_results = None
            if response.get("tool_calls"):
                tool_results = await self._execute_tools(context, response)

            # Step 6: Build and return AgentResult
            output = response.get("content", "")
            if tool_results:
                output = self._merge_tool_results(output, tool_results)

            # Store interaction in memory
            await self._store_memory(context, output)

            return AgentResult(
                agent_id=self.agent_id,
                success=True,
                output=output,
                metadata={
                    "model": self.model,
                    "tool_calls": response.get("tool_calls", []),
                    "tool_results": tool_results,
                    "memory_hits": len(memories),
                    "tokens_used": response.get("usage", {}),
                },
            )

        except Exception as e:
            logger.exception("Agent %s execution failed: %s", self.agent_id, str(e))
            return AgentResult(
                agent_id=self.agent_id,
                success=False,
                output=f"Agent execution failed: {str(e)}",
                metadata={"error": str(e), "error_type": type(e).__name__},
            )

    async def _delegate(self, context: AgentContext) -> AgentResult:
        """
        Delegate execution through meta_planner.dispatch() ONLY.
        This is Hard Rule #2: all inter-agent delegation MUST go through
        the meta planner for proper orchestration, cost tracking, and auditing.
        """
        delegate_to = context.ast.delegate_to
        logger.info(
            "Agent %s: Delegating intent '%s' to agent '%s' via meta_planner",
            self.agent_id,
            getattr(context.ast, "intent", "unknown"),
            delegate_to,
        )

        result = await self.meta_planner.dispatch(
            target_agent=delegate_to,
            context=context,
            source_agent=self.agent_id,
        )

        return AgentResult(
            agent_id=self.agent_id,
            success=result.get("success", False),
            output=result.get("output", "Delegation completed."),
            metadata={
                "delegated_to": delegate_to,
                "delegation_result": result,
            },
        )

    def _build_prompt(self, context: AgentContext, memories: List[Dict[str, Any]]) -> str:
        """
        Build the full prompt combining memory context and user message.
        """
        parts: List[str] = []

        # Add memory context if available
        if memories:
            memory_text = "\n".join(
                f"- [{m.get('type', 'memory')}] {m.get('content', '')}"
                for m in memories
            )
            parts.append(f"## Relevant Context from Memory\n{memory_text}")

        # Add conversation history if available
        if hasattr(context, "history") and context.history:
            history_text = "\n".join(
                f"{msg.get('role', 'user').capitalize()}: {msg.get('content', '')}"
                for msg in context.history[-5:]  # Last 5 messages for context window
            )
            parts.append(f"## Recent Conversation\n{history_text}")

        # Add the current user message
        user_message = getattr(context, "message", "") or getattr(context, "input", "")
        parts.append(f"## Current Request\n{user_message}")

        # Add available tools description
        if self.tools:
            tools_text = ", ".join(self.tools)
            parts.append(
                f"## Available Tools\nYou can use: {tools_text}\n"
                "To use a tool, include a tool_call in your response."
            )

        return "\n\n".join(parts)

    async def _execute_tools(
        self, context: AgentContext, response: Dict[str, Any]
    ) -> List[Dict[str, Any]]:
        """
        Execute tool calls from the LLM response via the tool registry.
        Tools that delegate to agents do so through meta_planner.dispatch().
        """
        from intelligence.tools.registry import ToolRegistry

        registry = ToolRegistry.instance()
        tool_calls = response.get("tool_calls", [])
        results: List[Dict[str, Any]] = []

        for tool_call in tool_calls:
            tool_id = tool_call.get("name") or tool_call.get("tool_id")
            params = tool_call.get("parameters") or tool_call.get("arguments", {})

            # Permission check: tool must be in agent's config.tools list
            if tool_id not in self.tools:
                logger.warning(
                    "Agent %s attempted to use unauthorized tool: %s",
                    self.agent_id,
                    tool_id,
                )
                results.append({
                    "tool_id": tool_id,
                    "success": False,
                    "error": f"Tool '{tool_id}' is not authorized for agent '{self.name}'.",
                })
                continue

            try:
                result = await registry.execute(
                    tool_id=tool_id,
                    context=context,
                    params=params,
                )
                results.append({
                    "tool_id": tool_id,
                    "success": True,
                    "result": result,
                })
            except Exception as e:
                logger.error(
                    "Agent %s: Tool '%s' execution failed: %s",
                    self.agent_id,
                    tool_id,
                    str(e),
                )
                results.append({
                    "tool_id": tool_id,
                    "success": False,
                    "error": str(e),
                })

        return results

    def _merge_tool_results(
        self, output: str, tool_results: List[Dict[str, Any]]
    ) -> str:
        """Merge tool execution results into the agent output."""
        if not tool_results:
            return output

        result_parts = [output] if output else []
        for tr in tool_results:
            tool_id = tr.get("tool_id", "unknown")
            if tr.get("success"):
                result_parts.append(f"[Tool: {tool_id}] {tr.get('result', '')}")
            else:
                result_parts.append(f"[Tool: {tool_id}] Error: {tr.get('error', 'Unknown error')}")

        return "\n\n".join(result_parts)

    async def _retrieve_memories(self, context: AgentContext) -> List[Dict[str, Any]]:
        """Retrieve relevant memories from the memory graph."""
        try:
            query = getattr(context, "message", "") or getattr(context, "input", "")
            if not query:
                return []

            memories = await self.memory_graph.search(
                query=query,
                agent_id=self.agent_id,
                limit=5,
            )
            return memories if memories else []
        except Exception as e:
            logger.warning("Agent %s: Memory retrieval failed: %s", self.agent_id, str(e))
            return []

    async def _store_memory(self, context: AgentContext, output: str) -> None:
        """Store the interaction in the memory graph for future retrieval."""
        try:
            user_message = getattr(context, "message", "") or getattr(context, "input", "")
            await self.memory_graph.store(
                agent_id=self.agent_id,
                content=f"User: {user_message}\nAgent: {output}",
                metadata={
                    "intent": getattr(context.ast, "intent", None) if hasattr(context, "ast") else None,
                    "session_id": getattr(context, "session_id", None),
                },
            )
        except Exception as e:
            logger.warning("Agent %s: Memory storage failed: %s", self.agent_id, str(e))

    def can_handle(self, intent: str) -> bool:
        """Check if any capability matches the given intent."""
        return any(cap == intent or cap in intent or intent in cap for cap in self.capabilities)
