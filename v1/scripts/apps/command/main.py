"""AI Command Bot — Telegram bot entry point.

Simplified boot sequence:
1. Load config from .env
2. Print boot banner with system checks
3. Clear stale Telegram polling lock
4. Wire up orchestrator, task registry, cost tracker
5. Register router and start polling
"""

import asyncio
import logging
import os
import sys
from pathlib import Path
from typing import NamedTuple

# Remove CLAUDECODE env var so Agent SDK can spawn Claude Code subprocesses.
# When developing inside a Claude Code session this var is set and blocks
# subprocess spawning — popping it here ensures clean agent launches.
os.environ.pop("CLAUDECODE", None)

from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode

from .bot import router, set_orchestrator
from .config import load_config
from .cost_tracker import CostTracker
from .orchestrator import Orchestrator


# ── Logging Setup ────────────────────────────────────────────────────────────

class _C:
    """ANSI color codes."""
    RESET   = "\033[0m"
    BOLD    = "\033[1m"
    DIM     = "\033[2m"
    RED     = "\033[31m"
    GREEN   = "\033[32m"
    YELLOW  = "\033[33m"
    CYAN    = "\033[36m"
    GREY    = "\033[90m"
    BRIGHT_CYAN   = "\033[96m"
    BRIGHT_GREEN  = "\033[92m"


class SystemCheck(NamedTuple):
    name: str
    passed: bool
    detail: str


def _setup_logging() -> None:
    """Configure root logger with timestamped format and suppress noisy libs."""
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(logging.Formatter(
        f"{_C.GREY}%(asctime)s{_C.RESET} | %(levelname)-7s | %(message)s",
        datefmt="%H:%M:%S",
    ))
    root = logging.getLogger()
    root.setLevel(logging.INFO)
    root.handlers.clear()
    root.addHandler(handler)

    # Suppress noisy third-party loggers
    for name in ("aiogram", "aiogram.dispatcher", "aiogram.event",
                 "httpx", "httpcore", "anthropic", "openai", "urllib3"):
        logging.getLogger(name).setLevel(logging.WARNING)


def _print_banner() -> None:
    """Print the branded boot banner."""
    banner = f"""\
{_C.BRIGHT_CYAN}{_C.BOLD}
   \u2554\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2557
   \u2551                                              \u2551
   \u2551       A I   C O M M A N D   B O T            \u2551
   \u2551       Telegram + Claude Code    v1.0          \u2551
   \u2551                                              \u2551
   \u255a\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u255d
{_C.RESET}"""
    print(banner, flush=True)


def _print_checks(checks: list[SystemCheck]) -> None:
    """Print system readiness checks with pass/fail indicators."""
    log = logging.getLogger("boot")
    for check in checks:
        if check.passed:
            log.info(f"{_C.GREEN}\u2713{_C.RESET} {check.name} {_C.GREY}({check.detail}){_C.RESET}")
        else:
            log.error(f"{_C.RED}\u2717{_C.RESET} {check.name} {_C.GREY}({check.detail}){_C.RESET}")


def _print_separator() -> None:
    """Print a visual separator line."""
    print(f"   {_C.GREY}{'─' * 46}{_C.RESET}", flush=True)


# ── Main ─────────────────────────────────────────────────────────────────────

async def main() -> None:
    # ── Boot banner ───────────────────────────────────────────────────────
    _setup_logging()
    _print_banner()

    boot = logging.getLogger("boot")
    system = logging.getLogger("system")

    # ── Load configuration ────────────────────────────────────────────────
    boot.info("Loading configuration...")
    config = load_config()
    config.log_dir.mkdir(parents=True, exist_ok=True)

    boot.info(
        "Model: %s  |  Budget: $%.2f/msg  |  Max turns: %d",
        config.general_agent_model, config.general_agent_max_budget, config.general_agent_max_turns,
    )
    _print_separator()

    # ── System checks ─────────────────────────────────────────────────────
    checks: list[SystemCheck] = []

    # Config loaded
    checks.append(SystemCheck(
        "Config loaded", True, f"model: {config.general_agent_model}",
    ))

    # Log directory
    checks.append(SystemCheck("Log directory", True, str(config.log_dir)))

    # ── Initialize bot ────────────────────────────────────────────────────
    bot = Bot(
        token=config.bot_token,
        default=DefaultBotProperties(parse_mode=ParseMode.HTML),
    )

    # Clear any stale Telegram polling lock (fixes TelegramConflictError).
    # deleteWebhook clears webhooks; getUpdates with offset=-1 flushes stale polls.
    boot.info("Clearing Telegram polling lock...")
    try:
        await bot.delete_webhook(drop_pending_updates=False)
    except Exception:
        pass
    # Flush any stale getUpdates long-poll by claiming the session briefly
    for attempt in range(5):
        try:
            await bot.get_updates(offset=-1, timeout=1)
            break
        except Exception:
            boot.info("Waiting for polling lock to release... (%d/5)", attempt + 1)
            await asyncio.sleep(3)

    # Verify Telegram connection
    try:
        me = await bot.get_me()
        checks.append(SystemCheck("Telegram", True, f"@{me.username}"))
    except Exception as e:
        checks.append(SystemCheck("Telegram", False, str(e)[:60]))

    _print_checks(checks)
    _print_separator()

    # Check for failures
    failed = [c for c in checks if not c.passed]
    if failed:
        boot.warning("Some checks failed — bot will start anyway")

    # ── Wire up components ────────────────────────────────────────────────
    cost_tracker = CostTracker(config.log_dir)
    orchestrator = Orchestrator(bot, config, cost_tracker)
    set_orchestrator(orchestrator, config)

    dp = Dispatcher()
    dp.include_router(router)

    @dp.startup()
    async def on_startup() -> None:
        system.info(
            f"{_C.BRIGHT_GREEN}{_C.BOLD}Online \u2014 polling for messages{_C.RESET}"
        )
        _print_separator()
        try:
            await bot.send_message(
                chat_id=config.group_id,
                text="Command Bot is online.",
            )
        except Exception:
            system.warning("Could not send startup message to Telegram")

    @dp.shutdown()
    async def on_shutdown() -> None:
        system.info("Shutting down...")

    await dp.start_polling(bot)


if __name__ == "__main__":
    _setup_logging()
    asyncio.run(main())
