"""Orchestrator for AI Command Bot — persistent CC agents with topic isolation."""

import asyncio
import base64
import os
import re
import uuid
from datetime import datetime, timezone
from pathlib import Path

from aiogram import Bot
from aiogram.types import BufferedInputFile, Message

from .config import Config
from .cost_tracker import CostTracker
from .formatting import (
    clean_agent_output,
    extract_image_paths,
    prepare_segments_for_delivery,
)
from .logger import get_logger
from .session_manager import SessionInfo, SessionManager
from .telegram_utils import (
    format_duration,
    send_segments,
)
from .worker import run_general_agent, run_general_prime

dispatch_log = get_logger("dispatch")
deliver_log = get_logger("deliver")
cost_log = get_logger("cost")
agent_log = get_logger("agent")


# ── Helper Functions ─────────────────────────────────────────────────────────


def _save_photos_to_disk(workspace_dir: str, photos: list[dict]) -> list[str]:
    """Save base64-encoded photos to disk and return relative paths from workspace root.

    Takes the photo dicts from bot.py (Anthropic image content blocks with
    'source.data' and 'source.media_type' keys), writes each to
    data/command/photos/, returns list of relative paths.
    """
    photo_dir = Path(workspace_dir) / "data" / "command" / "photos"
    photo_dir.mkdir(parents=True, exist_ok=True)

    paths = []
    for photo in photos:
        source = photo.get("source", photo)
        media_type = source.get("media_type", "image/jpeg")
        ext = media_type.split("/")[-1]
        if ext == "jpeg":
            ext = "jpg"
        filename = f"photo-{uuid.uuid4().hex[:12]}.{ext}"
        file_path = photo_dir / filename

        img_data = source.get("data", "")
        if isinstance(img_data, str):
            img_data = base64.b64decode(img_data)
        file_path.write_bytes(img_data)

        rel_path = str(file_path.relative_to(workspace_dir))
        paths.append(rel_path)
        dispatch_log.debug("Saved photo: %s", rel_path)

    return paths


def _build_photo_prompt(photo_paths: list[str]) -> str:
    """Build prompt text instructing the agent to use Read tool on saved photos."""
    if len(photo_paths) == 1:
        return (
            f"\n\n[Photo attached — saved to `{photo_paths[0]}`. "
            "Use the Read tool to view and analyze it.]"
        )

    lines = "\n".join(f"- `{p}`" for p in photo_paths)
    return (
        f"\n\n[{len(photo_paths)} photos attached — saved to:\n{lines}\n"
        "Use the Read tool to view and analyze them.]"
    )


def _cleanup_old_photos(workspace_dir: str, max_age_hours: int = 24) -> None:
    """Delete photos older than max_age_hours from the temp photo directory."""
    photo_dir = Path(workspace_dir) / "data" / "command" / "photos"
    if not photo_dir.exists():
        return

    cutoff = datetime.now().timestamp() - (max_age_hours * 3600)
    removed = 0
    for f in photo_dir.iterdir():
        if f.is_file() and f.stat().st_mtime < cutoff:
            f.unlink()
            removed += 1
    if removed:
        dispatch_log.info("Cleaned up %d old photo(s) from %s", removed, photo_dir)


def _extract_created_files(text: str) -> list[str]:
    """Extract file paths mentioned in agent output.

    Detects files in outputs/, reference/research/, and ~/projects/.
    Returns paths as found in text, deduplicated and ordered.
    """
    patterns = [
        r"outputs/[\w./-]+\.(?:md|pdf|csv|txt|json|py|html)",
        r"reference/research/[\w./-]+\.(?:md|pdf|csv|txt|json|py|html)",
        r"~/projects/[\w./-]+\.(?:md|pdf|csv|txt|json|py|html)",
    ]
    matches = []
    for pat in patterns:
        matches.extend(re.findall(pat, text))
    # Deduplicate while preserving order, exclude chart PNGs (handled separately)
    seen = set()
    result = []
    for m in matches:
        if m not in seen and not m.startswith("outputs/charts/"):
            seen.add(m)
            result.append(m)
    return result


def _escape_html(text: str) -> str:
    """Escape HTML special characters for Telegram."""
    text = text.replace("&", "&amp;")
    text = text.replace("<", "&lt;")
    text = text.replace(">", "&gt;")
    return text


# ── Orchestrator ─────────────────────────────────────────────────────────────


class Orchestrator:
    """Core engine for the AI Command Bot.

    Routes messages to the appropriate handler, manages persistent CC agent
    sessions, and handles delivery of results back to Telegram.
    """

    def __init__(
        self,
        bot: Bot,
        config: Config,
        cost_tracker: CostTracker,
    ):
        self.bot = bot
        self.config = config
        self.cost_tracker = cost_tracker

        # Persistent CC agent session manager
        self.sessions = SessionManager(
            os.path.join(config.workspace_dir, "data", "command")
        )

        # Clean up old temp photos on startup
        _cleanup_old_photos(config.workspace_dir)

    # ── General Message Handler ──────────────────────────────────────────────

    async def handle_general_message(
        self,
        message: Message,
        text_override: str | None = None,
        photos: list[dict] | None = None,
    ) -> None:
        """Handle a message in the General topic — route to CC agent.

        Parses explicit commands (/help, /cost, /reset, /compact,
        /new, /name) and routes everything else to the persistent CC agent
        session.
        """
        text = (text_override or message.text or "").strip()
        if not text and not photos:
            return

        # Handle explicit commands directly (no AI needed)
        if text.startswith("/help") or text.startswith("/start"):
            await self._cmd_help(message)
            return
        if text.startswith("/reset"):
            self.sessions.delete("general")
            await message.reply("Session reset. Next message starts fresh.")
            return
        if text.startswith("/compact"):
            await self._compact_session("general", message)
            return
        if text.startswith("/new"):
            await self._spawn_new_agent(message, text)
            return
        if text.startswith("/name"):
            await self._rename_topic(message)
            return

        # Route to CC agent
        await self._handle_cc_agent_message(
            topic_key="general",
            message=message,
            text=text,
            photos=photos,
            model=self.config.general_agent_model,
        )

    # ── Agent Topic Handler ──────────────────────────────────────────────────

    async def handle_agent_topic_message(
        self,
        message: Message,
        text_override: str | None = None,
        photos: list[dict] | None = None,
    ) -> None:
        """Handle a message in a spawned agent topic — route to CC agent session.

        Supports /reset, /compact, and /name commands within agent topics.
        """
        text = (text_override or message.text or "").strip()
        topic_key = str(message.message_thread_id)

        # Handle commands within agent topics
        if text.startswith("/reset"):
            self.sessions.delete(topic_key)
            await message.reply("Session reset. Next message re-primes.")
            return
        if text.startswith("/compact"):
            await self._compact_session(topic_key, message)
            return
        if text.startswith("/name"):
            await self._rename_topic(message)
            return

        session = self.sessions.get(topic_key)
        if not session:
            await message.reply("No session for this topic. It may have been reset.")
            return

        await self._handle_cc_agent_message(
            topic_key=topic_key,
            message=message,
            text=text,
            photos=photos,
            model=session.model,
        )

    # ── Core CC Agent Message Handler ────────────────────────────────────────

    async def _handle_cc_agent_message(
        self,
        topic_key: str,
        message: Message,
        text: str,
        photos: list[dict] | None = None,
        model: str = "sonnet",
    ) -> None:
        """Send a message to a CC agent session and deliver the response.

        This is the core persistent agent flow:
        1. If no session exists, prime one (run_general_prime)
        2. Save any photos to disk and append Read instructions
        3. Run the agent on the session (run_general_agent)
        4. Handle errors (delete broken session, show error)
        5. Update session usage tracking
        6. Deliver: clean output -> prepare segments -> send to Telegram
        7. Send any created files (images as photos, others as documents)
        8. Show cost footer
        9. Warn if context is getting large (>180K tokens)
        """
        session = self.sessions.get(topic_key)

        # Determine chat_id and thread_id for response delivery
        chat_id = self.config.group_id
        thread_id = message.message_thread_id

        # Show typing indicator
        try:
            await self.bot.send_chat_action(
                chat_id=chat_id,
                action="typing",
                message_thread_id=thread_id,
            )
        except Exception:
            pass

        if not session:
            # No session — need to prime first
            agent_log.info("No session for %s — priming...", topic_key)
            prime_result = await run_general_prime(
                workspace_dir=self.config.workspace_dir,
                model=model,
            )
            if prime_result.is_error:
                await message.reply(
                    f"Prime failed: {prime_result.result_text[:400]}"
                )
                return

            session = SessionInfo(
                session_id=prime_result.session_id,
                model=model,
                created=datetime.now(timezone.utc).isoformat(),
                name="General" if topic_key == "general" else f"Agent — {topic_key}",
            )
            self.sessions.save(topic_key, session)
            agent_log.info(
                "Primed %s session (cost=$%.2f)", topic_key, prime_result.cost_usd
            )
            self.cost_tracker.log_inline(topic_key, prime_result.cost_usd)

        # Save photos to disk and add Read tool instructions
        effective_text = text
        if photos:
            photo_paths = _save_photos_to_disk(self.config.workspace_dir, photos)
            effective_text += _build_photo_prompt(photo_paths)

        # Run task on existing session
        agent_log.info("CC agent processing (%s, %s)...", topic_key, session.model)
        result = await run_general_agent(
            prompt=effective_text,
            session_id=session.session_id,
            workspace_dir=self.config.workspace_dir,
            model=session.model,
            max_turns=self.config.general_agent_max_turns,
            max_budget_usd=self.config.general_agent_max_budget,
        )

        # Handle fatal SDK errors — delete broken session so next message re-primes
        if result.is_error:
            agent_log.error(
                "CC agent error (%s): %s", topic_key, result.result_text[:200]
            )
            self.sessions.delete(topic_key)
            await self.bot.send_message(
                chat_id=chat_id,
                text=(
                    "\u274c Agent error — session reset. Send your message again "
                    "to start fresh.\n\n"
                    f"<i>{_escape_html(result.result_text[:300])}</i>"
                ),
                message_thread_id=thread_id,
                parse_mode="HTML",
            )
            return

        # Update session with new session_id (may change after compaction) and usage
        input_tokens = 0
        if result.usage and isinstance(result.usage, dict):
            input_tokens = result.usage.get("input_tokens", 0)

        if result.session_id:
            session.session_id = result.session_id
        self.sessions.update_usage(
            topic_key, result.cost_usd, result.num_turns, input_tokens
        )

        # Deliver response
        cleaned = clean_agent_output(result.result_text)
        segments = prepare_segments_for_delivery(cleaned)
        await send_segments(
            self.bot,
            chat_id,
            segments,
            message_thread_id=thread_id,
        )

        # Send any created files (images as photos, others as documents)
        await self._send_created_files(cleaned, chat_id, thread_id)

        # Duration footer
        duration_str = format_duration(result.duration_ms)
        await self.bot.send_message(
            chat_id=chat_id,
            text=duration_str,
            message_thread_id=thread_id,
        )

        # Context warning if approaching limit
        if input_tokens > self.config.context_warning_threshold:
            pct = int((input_tokens / 200000) * 100)
            await self.bot.send_message(
                chat_id=chat_id,
                text=(
                    f"\u26a0\ufe0f Context at {pct}% ({input_tokens:,} tokens). "
                    "Use /compact to compress or /new for a fresh agent."
                ),
                message_thread_id=thread_id,
            )

        self.cost_tracker.log_inline(topic_key, result.cost_usd)

    # ── Send Created Files ───────────────────────────────────────────────────

    async def _send_created_files(
        self, text: str, chat_id: int, thread_id: int | None
    ) -> None:
        """Detect and send agent-created files from response text.

        Sends images as Telegram photos (with caption) and other files
        (PDFs, CSVs, etc.) as Telegram documents.
        """
        from .telegram_utils import send_photo_file

        # Send images as Telegram photos
        image_paths = extract_image_paths(text)
        for title, rel_path in image_paths:
            full_path = Path(self.config.workspace_dir) / rel_path
            if full_path.exists():
                try:
                    await send_photo_file(
                        self.bot,
                        chat_id,
                        str(full_path),
                        caption=title or None,
                        message_thread_id=thread_id,
                    )
                    deliver_log.info("Sent image: %s", rel_path)
                except Exception:
                    deliver_log.exception("Failed to send image: %s", rel_path)

        # Send PDFs and other output files as documents
        created_files = _extract_created_files(text)
        for fpath in created_files:
            full_path = Path(self.config.workspace_dir) / fpath
            if full_path.exists() and full_path.stat().st_size > 0:
                try:
                    doc = BufferedInputFile(
                        full_path.read_bytes(),
                        filename=full_path.name,
                    )
                    await self.bot.send_document(
                        chat_id=chat_id,
                        document=doc,
                        caption=f"\U0001f4ce {full_path.name}",
                        message_thread_id=thread_id,
                    )
                    deliver_log.info("Sent file: %s", fpath)
                except Exception:
                    deliver_log.exception("Failed to send file: %s", fpath)

    # ── Spawn New Agent ──────────────────────────────────────────────────────

    async def _spawn_new_agent(self, message: Message, text: str) -> None:
        """Handle /new [sonnet|opus] — spawn a fresh agent in a new forum topic.

        Creates a new Telegram forum topic with a timestamped name,
        posts a "Priming..." message, and kicks off background priming.
        """
        parts = text.strip().split()
        model = "sonnet"
        if len(parts) >= 2 and parts[1].lower() in ("opus", "sonnet"):
            model = parts[1].lower()

        # Create forum topic with UTC timestamp
        now = datetime.now(timezone.utc)
        topic_name = f"Agent \u2014 {now.strftime('%b %d %I:%M%p')} UTC"

        try:
            topic = await self.bot.create_forum_topic(
                chat_id=self.config.group_id,
                name=topic_name,
                icon_color=7322096,
            )
        except Exception as e:
            dispatch_log.exception("Failed to create forum topic")
            await message.reply(f"Failed to create topic: {str(e)[:200]}")
            return

        topic_id = topic.message_thread_id

        await self.bot.send_message(
            chat_id=self.config.group_id,
            text=f"\u23f3 Priming {model} agent...",
            message_thread_id=topic_id,
        )

        # Prime in background
        asyncio.create_task(self._prime_and_register(topic_id, topic_name, model))

        # Confirm in General
        await message.reply(f"Spinning up {model} agent \u2192 {topic_name}")

    async def _prime_and_register(
        self, topic_id: int, topic_name: str, model: str
    ) -> None:
        """Prime an agent and register the session.

        Runs in the background after /new. On success, saves the session
        and posts a confirmation. On failure, posts an error message.
        """
        try:
            result = await run_general_prime(
                workspace_dir=self.config.workspace_dir,
                model=model,
            )
            if result.is_error:
                await self.bot.send_message(
                    chat_id=self.config.group_id,
                    text=f"\u274c Prime failed: {result.result_text[:300]}",
                    message_thread_id=topic_id,
                )
                return

            session = SessionInfo(
                session_id=result.session_id,
                model=model,
                created=datetime.now(timezone.utc).isoformat(),
                name=topic_name,
            )
            self.sessions.save(str(topic_id), session)

            await self.bot.send_message(
                chat_id=self.config.group_id,
                text=f"\u2705 Primed and ready. ({model})",
                message_thread_id=topic_id,
            )
        except Exception as e:
            await self.bot.send_message(
                chat_id=self.config.group_id,
                text=f"\u274c Error: {str(e)[:300]}",
                message_thread_id=topic_id,
            )

    # ── Rename Topic ─────────────────────────────────────────────────────────

    async def _rename_topic(self, message: Message) -> None:
        """Ask the agent to suggest a short name, then rename the forum topic.

        Works in any agent topic. The agent uses conversation context to
        generate a descriptive name (max 60 chars with emoji prefix).
        """
        topic_id = message.message_thread_id
        if not topic_id:
            await message.reply("Use /name inside an agent topic to rename it.")
            return

        topic_key = str(topic_id)
        session = self.sessions.get(topic_key)
        if not session:
            await message.reply("No agent session for this topic.")
            return

        # Ask the agent to suggest a name
        result = await run_general_agent(
            prompt=(
                "Based on our conversation so far, suggest a short descriptive name "
                "for this topic (max 60 chars). Include a relevant emoji prefix. "
                "Examples: 'Data Pipeline Refactor', 'Feb Revenue Analysis', "
                "'Marketing Copy Review'. Respond with ONLY the topic name, nothing else."
            ),
            session_id=session.session_id,
            workspace_dir=self.config.workspace_dir,
            model=session.model,
            max_turns=3,
            max_budget_usd=0.50,
        )

        # Update session_id (may change after compaction)
        if result.session_id:
            session.session_id = result.session_id
            self.sessions.save(topic_key, session)

        # Bail if the agent errored
        if result.is_error:
            await message.reply("Agent error — try /reset and then /name again.")
            return

        # Sanitize: strip newlines, quotes, limit length (Telegram max 128 chars)
        new_name = clean_agent_output(result.result_text).strip()
        new_name = new_name.replace("\n", " ").strip("\"'")[:128]
        if not new_name:
            await message.reply("Agent returned empty name suggestion.")
            return

        try:
            await self.bot.edit_forum_topic(
                chat_id=self.config.group_id,
                message_thread_id=topic_id,
                name=new_name,
            )
            # Only update session name AFTER Telegram confirms success
            session.name = new_name
            self.sessions.save(topic_key, session)
            await message.reply(f"Renamed to: {new_name}")
        except Exception as e:
            await message.reply(f"Failed to rename: {str(e)[:200]}")

    # ── Compact Session ──────────────────────────────────────────────────────

    async def _compact_session(self, topic_key: str, message: Message) -> None:
        """Trigger context compaction for a session.

        Asks the agent to summarize key information and ongoing threads,
        which reduces context window usage. The session ID may change
        after compaction.
        """
        session = self.sessions.get(topic_key)
        if not session:
            await message.reply("No active session to compact.")
            return

        await message.reply("Compacting context...")

        result = await run_general_agent(
            prompt=(
                "Please compact your context. Summarize the key information, "
                "decisions, and ongoing threads from our conversation so far. "
                "After compacting, confirm what you've retained."
            ),
            session_id=session.session_id,
            workspace_dir=self.config.workspace_dir,
            model=session.model,
            max_turns=5,
            max_budget_usd=1.00,
        )

        # Update session ID (may change after compaction)
        if result.session_id:
            session.session_id = result.session_id
            self.sessions.save(topic_key, session)

        cleaned = clean_agent_output(result.result_text)
        segments = prepare_segments_for_delivery(cleaned)
        await send_segments(
            self.bot,
            self.config.group_id,
            segments,
            message_thread_id=message.message_thread_id,
        )

    # ── Commands ─────────────────────────────────────────────────────────────

    async def _cmd_help(self, message: Message) -> None:
        """Show available commands."""
        await message.reply(
            "<b>AI Command Bot</b>\n\n"
            "Chat with me in General \u2014 I'm a persistent Claude Code agent "
            "with full workspace access.\n\n"
            "<b>Commands:</b>\n"
            "/new [sonnet|opus] \u2014 Spawn a fresh agent in a new topic\n"
            "/name \u2014 Rename current topic based on conversation\n"
            "/compact \u2014 Compress context when running low\n"
            "/reset \u2014 Clear session and start fresh\n"
            "/help \u2014 This message\n\n"
            "<b>Tips:</b>\n"
            "\u2022 Use /name after a conversation to give your topic a descriptive title\n"
            "\u2022 Use /compact when the agent starts forgetting earlier context\n"
            "\u2022 Send voice notes, photos, and screenshots \u2014 the agent sees everything\n\n"
            "Reply in any agent topic for follow-ups with full context.",
            parse_mode="HTML",
        )

