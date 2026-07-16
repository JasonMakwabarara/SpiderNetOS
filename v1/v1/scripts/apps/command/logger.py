"""Custom logging for AI Command Bot — colors, categories, boot banner."""

import logging
import os
import sys
from logging.handlers import RotatingFileHandler
from typing import NamedTuple


# -- ANSI Color Codes ----------------------------------------------------------

class _C:
    """ANSI color codes."""
    RESET   = "\033[0m"
    BOLD    = "\033[1m"
    DIM     = "\033[2m"

    # Foreground
    RED     = "\033[31m"
    GREEN   = "\033[32m"
    YELLOW  = "\033[33m"
    BLUE    = "\033[34m"
    MAGENTA = "\033[35m"
    CYAN    = "\033[36m"
    WHITE   = "\033[37m"
    GREY    = "\033[90m"

    # Bright
    BRIGHT_GREEN  = "\033[92m"
    BRIGHT_YELLOW = "\033[93m"
    BRIGHT_CYAN   = "\033[96m"
    BRIGHT_WHITE  = "\033[97m"


# -- Category Mapping ----------------------------------------------------------
# Logger names -> (LABEL, COLOR) for the structured log format.
# We match by prefix so child loggers inherit their parent's category.

class _Category(NamedTuple):
    label: str
    color: str


# Map logger name prefixes to display categories
_CATEGORY_MAP: list[tuple[str, _Category]] = [
    ("command.boot",     _Category("BOOT",     _C.BRIGHT_CYAN)),
    ("command.system",   _Category("SYSTEM",   _C.CYAN)),
    ("command.message",  _Category("MESSAGE",  _C.GREEN)),
    ("command.agent",    _Category("AGENT",    _C.BLUE)),
    ("command.tool",     _Category("TOOL",     _C.BLUE)),
    ("command.dispatch", _Category("DISPATCH", _C.YELLOW)),
    ("command.worker",   _Category("WORKER",   _C.YELLOW)),
    ("command.deliver",  _Category("DELIVER",  _C.MAGENTA)),
    ("command.cost",     _Category("COST",     _C.MAGENTA)),
    ("command",          _Category("CMD",      _C.WHITE)),
]

# Default for unknown loggers
_DEFAULT_CATEGORY = _Category("LOG", _C.GREY)

# Loggers to suppress (set to WARNING)
_SUPPRESSED_LOGGERS = [
    "aiogram",
    "aiogram.dispatcher",
    "aiogram.event",
    "httpx",
    "httpcore",
    "anthropic",
    "openai",
    "urllib3",
    "matplotlib",
]


def _get_category(logger_name: str) -> _Category:
    """Find the category for a logger name by prefix matching."""
    for prefix, cat in _CATEGORY_MAP:
        if logger_name.startswith(prefix):
            return cat
    return _DEFAULT_CATEGORY


# -- Custom Formatter ----------------------------------------------------------

class CommandFormatter(logging.Formatter):
    """Colored, category-based log formatter.

    Output format: HH:MM:SS | CATEGORY | message
    """

    LEVEL_COLORS = {
        logging.DEBUG:    _C.GREY,
        logging.INFO:     "",       # Category color is used
        logging.WARNING:  _C.YELLOW,
        logging.ERROR:    _C.RED,
        logging.CRITICAL: _C.RED + _C.BOLD,
    }

    def format(self, record: logging.LogRecord) -> str:
        # Time
        time_str = self.formatTime(record, "%H:%M:%S")

        # Category
        cat = _get_category(record.name)

        # Level-based color override for warnings/errors
        level_color = self.LEVEL_COLORS.get(record.levelno, "")

        # For errors/warnings, override the category color
        if record.levelno >= logging.WARNING:
            msg_color = level_color
            label = record.levelname if record.levelno >= logging.ERROR else cat.label
        else:
            msg_color = cat.color
            label = cat.label

        # Build the formatted line
        line = (
            f"{_C.GREY}{time_str}{_C.RESET}"
            f" {_C.GREY}\u2502{_C.RESET} "
            f"{msg_color}{label:<8}{_C.RESET}"
            f" {_C.GREY}\u2502{_C.RESET} "
            f"{msg_color}{record.getMessage()}{_C.RESET}"
        )

        # Append exception info if present
        if record.exc_info and record.exc_info[0]:
            line += "\n" + self.formatException(record.exc_info)

        return line


# -- Setup ---------------------------------------------------------------------

class PlainFormatter(logging.Formatter):
    """Plain-text formatter for file logging (no ANSI colors).

    Output format: YYYY-MM-DD HH:MM:SS | CATEGORY | LEVEL | message
    """

    def format(self, record: logging.LogRecord) -> str:
        time_str = self.formatTime(record, "%Y-%m-%d %H:%M:%S")
        cat = _get_category(record.name)
        line = f"{time_str} | {cat.label:<8} | {record.levelname:<7} | {record.getMessage()}"
        if record.exc_info and record.exc_info[0]:
            line += "\n" + self.formatException(record.exc_info)
        return line


def setup_logging() -> None:
    """Configure the root logger with the custom formatter and suppress noisy loggers."""
    # Console handler (colored)
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(CommandFormatter())

    root = logging.getLogger()
    root.setLevel(logging.INFO)
    # Remove any existing handlers
    root.handlers.clear()
    root.addHandler(handler)

    # File handler (plain text, rotating)
    # Derive workspace root from this file: apps/command/logger.py -> ../../
    workspace_root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    log_dir = os.path.join(workspace_root, "data")
    os.makedirs(log_dir, exist_ok=True)
    log_path = os.path.join(log_dir, "command.log")
    file_handler = RotatingFileHandler(
        log_path, maxBytes=5 * 1024 * 1024, backupCount=3, encoding="utf-8"
    )
    file_handler.setFormatter(PlainFormatter())
    root.addHandler(file_handler)

    # Suppress noisy third-party loggers
    for name in _SUPPRESSED_LOGGERS:
        logging.getLogger(name).setLevel(logging.WARNING)


# -- Structured Loggers --------------------------------------------------------
# These are the loggers used throughout the app for category-based logging.

def get_logger(category: str) -> logging.Logger:
    """Get a category logger. Category should be one of:
    boot, system, message, agent, tool, dispatch, worker, deliver, cost
    """
    return logging.getLogger(f"command.{category}")


# -- Boot Banner ---------------------------------------------------------------

BANNER = f"""\
{_C.BRIGHT_CYAN}{_C.BOLD}
   \u2554\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2557
   \u2551                                              \u2551
   \u2551       A I   C O M M A N D                    \u2551
   \u2551       B O T              v1.0                \u2551
   \u2551                                              \u2551
   \u2560\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2563
   \u2551  AI Command Center                           \u2551
   \u2551  Telegram + Claude Agent SDK                 \u2551
   \u255a\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u255d
{_C.RESET}"""


def print_banner() -> None:
    """Print the branded boot banner."""
    print(BANNER, flush=True)


def print_config_summary(
    orchestrator_model: str,
    worker_model: str,
    max_concurrent: int,
    max_budget: float,
) -> None:
    """Print configuration summary after the banner."""
    log = get_logger("boot")
    log.info("Orchestrator: %s  |  Worker: %s", orchestrator_model, worker_model)
    log.info("Max concurrent: %d  |  Budget limit: $%.2f/task", max_concurrent, max_budget)


class SystemCheck(NamedTuple):
    name: str
    passed: bool
    detail: str


def print_system_checks(checks: list[SystemCheck]) -> None:
    """Print system readiness checks with pass/fail indicators."""
    log = get_logger("boot")
    for check in checks:
        if check.passed:
            log.info(f"{_C.GREEN}\u2713{_C.RESET} {check.name} {_C.GREY}({check.detail}){_C.RESET}")
        else:
            log.error(f"{_C.RED}\u2717{_C.RESET} {check.name} {_C.GREY}({check.detail}){_C.RESET}")


def print_separator() -> None:
    """Print a visual separator line."""
    print(f"   {_C.GREY}{'\u2500' * 46}{_C.RESET}", flush=True)


def print_ready() -> None:
    """Print the ready-to-go message."""
    log = get_logger("system")
    log.info(f"{_C.BRIGHT_GREEN}{_C.BOLD}Online \u2014 polling for messages{_C.RESET}")
    print_separator()
