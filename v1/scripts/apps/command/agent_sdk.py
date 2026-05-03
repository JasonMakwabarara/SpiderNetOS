"""Shared Agent SDK worker for AI Command Bot pipelines.

Wraps the claude_agent_sdk package and provides reusable functions
for priming a workspace, running tasks on existing sessions, and
spawning standalone agents.

Core pattern: prime the agent with full workspace context via a
prime command file, then run tasks on the primed session.
"""

import logging
import os
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path

from claude_agent_sdk import (
    ClaudeAgentOptions,
    AssistantMessage,
    ResultMessage,
    TextBlock,
    ToolUseBlock,
    query,
)

logger = logging.getLogger(__name__)

_COMMANDS_DIR = Path(__file__).resolve().parent.parent.parent / ".claude" / "commands"
PRIME_COMMAND_PATH = _COMMANDS_DIR / "prime.md"
PRIME_TELEGRAM_PATH = _COMMANDS_DIR / "prime-telegram.md"

DEFAULT_SYSTEM_APPEND = (
    "You are an autonomous agent operating in this workspace. "
    "You have full access to all workspace files and tools."
)


@dataclass
class WorkerResult:
    """Result from an agent run."""

    result_text: str
    cost_usd: float
    duration_ms: int
    num_turns: int
    session_id: str | None
    is_error: bool
    usage: dict | None = None


def _load_prime_prompt(prime_command: str | None = None) -> str:
    """Load a prime command file as the priming prompt.

    Args:
        prime_command: Path to a prime command .md file.
            Defaults to the standard prime.md.
    """
    path = Path(prime_command) if prime_command else PRIME_COMMAND_PATH
    try:
        return path.read_text()
    except FileNotFoundError:
        return (
            "Read the key context files in this workspace to prime yourself.\n"
            "Look for any overview, strategy, or configuration files.\n\n"
            "After reading, provide a brief summary and confirm readiness."
        )


def create_options(
    workspace_dir: str,
    model: str,
    max_turns: int,
    max_budget_usd: float,
    system_append: str | None = None,
    allowed_tools: list[str] | None = None,
) -> ClaudeAgentOptions:
    """Create ClaudeAgentOptions with a configurable system prompt suffix.

    Args:
        workspace_dir: Path to the workspace root.
        model: Model to use (e.g., "sonnet", "haiku").
        max_turns: Maximum agent turns.
        max_budget_usd: Maximum budget for the run.
        system_append: Custom text appended to the system prompt.
            Defaults to a generic autonomous agent prompt.
        allowed_tools: List of allowed tools. Defaults to standard set.
    """
    # Remove CLAUDECODE from env to avoid conflicts when spawning sub-agents
    env_clean = {k: v for k, v in os.environ.items() if k != "CLAUDECODE"}

    return ClaudeAgentOptions(
        system_prompt={
            "type": "preset",
            "preset": "claude_code",
            "append": system_append or DEFAULT_SYSTEM_APPEND,
        },
        setting_sources=["project"],
        cwd=workspace_dir,
        allowed_tools=allowed_tools or [
            "Read", "Write", "Edit", "Bash", "Glob", "Grep",
            "WebSearch", "WebFetch", "Task",
        ],
        permission_mode="bypassPermissions",
        max_turns=max_turns,
        max_budget_usd=max_budget_usd,
        model=model,
        env=env_clean,
    )


async def _run_agent(
    prompt: str,
    options: ClaudeAgentOptions,
    on_tool_use: Callable | None = None,
    session_id: str | None = None,
) -> WorkerResult:
    """Run an agent query and capture only the final assistant text.

    IMPORTANT: We never return/break from inside the ``async for`` loop.
    Doing so triggers ``aclose()`` on the SDK's async generator, which
    crashes with ``RuntimeError: Attempted to exit cancel scope in a
    different task`` due to anyio cleanup across asyncio task boundaries.
    Instead we store the result and let the loop exhaust naturally.
    """
    latest_text_parts: list[str] = []
    result: WorkerResult | None = None

    if session_id:
        options.resume = session_id

    try:
        async for message in query(prompt=prompt, options=options):
            if isinstance(message, AssistantMessage):
                new_text_parts = []
                for block in message.content:
                    if isinstance(block, TextBlock):
                        new_text_parts.append(block.text)
                    elif isinstance(block, ToolUseBlock):
                        if on_tool_use:
                            await on_tool_use(block.name)
                # Only overwrite if this message had text — preserves
                # the report when the final message is tool-only
                # (e.g. a TodoWrite to mark tasks complete)
                if new_text_parts:
                    latest_text_parts = new_text_parts

            elif isinstance(message, ResultMessage):
                result = WorkerResult(
                    result_text="\n".join(latest_text_parts),
                    cost_usd=message.total_cost_usd or 0,
                    duration_ms=message.duration_ms or 0,
                    num_turns=message.num_turns or 0,
                    session_id=message.session_id,
                    is_error=message.is_error or False,
                    usage=message.usage,
                )
                # DO NOT return/break here — let the async for loop
                # exhaust naturally (anyio cancel scope bugfix).

    except RuntimeError as e:
        if "cancel scope" in str(e):
            logger.warning("Suppressed anyio cancel scope error during SDK cleanup: %s", e)
            if result:
                return result
        else:
            raise
    except Exception as e:
        logger.exception("Agent SDK error")
        return WorkerResult(
            result_text=f"Agent error: {e}",
            cost_usd=0, duration_ms=0, num_turns=0,
            session_id=None, is_error=True,
        )

    if result:
        return result

    return WorkerResult(
        result_text="\n".join(latest_text_parts) or "Agent completed with no output.",
        cost_usd=0, duration_ms=0, num_turns=0,
        session_id=None, is_error=True,
    )


async def run_prime(
    workspace_dir: str,
    model: str = "sonnet",
    max_turns: int = 15,
    max_budget_usd: float = 2.00,
    system_append: str | None = None,
    on_tool_use: Callable | None = None,
    prime_command: str | None = None,
) -> WorkerResult:
    """Run the workspace prime step — reads context files and returns a summary.

    Args:
        prime_command: Path to a prime command .md file.
            Defaults to the standard prime.md.

    Returns a WorkerResult with session_id for resumption.
    """
    prompt = _load_prime_prompt(prime_command)
    options = create_options(
        workspace_dir, model, max_turns, max_budget_usd,
        system_append=system_append,
    )
    return await _run_agent(prompt, options, on_tool_use=on_tool_use)


async def run_task_on_session(
    prompt: str,
    session_id: str,
    workspace_dir: str,
    model: str = "sonnet",
    max_turns: int = 25,
    max_budget_usd: float = 5.00,
    system_append: str | None = None,
    on_tool_use: Callable | None = None,
) -> WorkerResult:
    """Run a task on an existing primed session — agent retains full context."""
    options = create_options(
        workspace_dir, model, max_turns, max_budget_usd,
        system_append=system_append,
    )
    return await _run_agent(prompt, options, on_tool_use=on_tool_use, session_id=session_id)


async def run_worker(
    prompt: str,
    workspace_dir: str,
    model: str = "sonnet",
    max_turns: int = 25,
    max_budget_usd: float = 5.00,
    system_append: str | None = None,
    allowed_tools: list[str] | None = None,
    on_tool_use: Callable | None = None,
) -> WorkerResult:
    """Spawn a standalone Claude Code agent (no priming)."""
    options = create_options(
        workspace_dir, model, max_turns, max_budget_usd,
        system_append=system_append,
        allowed_tools=allowed_tools,
    )
    return await _run_agent(prompt, options, on_tool_use=on_tool_use)
