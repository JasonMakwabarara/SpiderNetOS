"""Aiogram bot handlers for AI Command Bot."""

import asyncio
import base64
import io
import sys
from dataclasses import dataclass, field

from aiogram import Bot, Router
from aiogram.filters import Command
from aiogram.types import Message

from .config import Config
from .logger import get_logger
from .orchestrator import Orchestrator

msg_log = get_logger("message")
sys_log = get_logger("system")

router = Router()

_orchestrator: Orchestrator | None = None
_config: Config | None = None
_owner_id: int | None = None

# Message batching — Telegram splits long pastes into multiple messages.
# We collect them with a short delay and process as one.
_DEBOUNCE_SECONDS = 1.5


@dataclass
class _BufferedItem:
    """A text string, voice transcript, or photo queued for batching."""

    message: Message  # the original Telegram message (used for reply target)
    text: str  # the text content (typed or transcribed)
    photos: list[dict] = field(default_factory=list)  # Pre-built Anthropic image content blocks


_message_buffer: list[_BufferedItem] = []
_debounce_task: asyncio.Task | None = None


def set_orchestrator(orchestrator: Orchestrator, config: Config) -> None:
    """Wire up the orchestrator and config for handlers."""
    global _orchestrator, _config
    _orchestrator = orchestrator
    _config = config


def _is_authorized(message: Message) -> bool:
    """Check if the message is from an authorized user.

    Auto-captures the first human user as the owner. All subsequent
    messages from non-owner users are silently ignored. This means
    the first person to message the bot in the configured group
    becomes the sole operator.
    """
    global _owner_id
    if not message.from_user or message.from_user.is_bot:
        return False
    # Auto-capture the first human user as owner
    if _owner_id is None:
        _owner_id = message.from_user.id
        sys_log.info(
            "Owner captured: %s (id=%d)",
            message.from_user.full_name,
            _owner_id,
        )
        return True
    return message.from_user.id == _owner_id


async def _transcribe_voice(bot: Bot, message: Message) -> str | None:
    """Download a Telegram voice note and transcribe via OpenAI Whisper.

    Returns the transcribed text, or None if transcription fails
    (missing API key, missing package, API error, etc.).
    """
    if not _config or not _config.openai_api_key:
        msg_log.warning("Voice note received but OPENAI_API_KEY not configured")
        return None

    try:
        from openai import AsyncOpenAI
    except ImportError:
        msg_log.error("openai package not installed — cannot transcribe voice notes")
        return None

    voice = message.voice
    if not voice:
        return None

    try:
        # Download OGG from Telegram
        file = await bot.get_file(voice.file_id)
        bio = io.BytesIO()
        await bot.download_file(file.file_path, bio)
        bio.seek(0)
        bio.name = "voice.ogg"

        # Transcribe with Whisper
        client = AsyncOpenAI(api_key=_config.openai_api_key)
        transcript = await client.audio.transcriptions.create(
            model="whisper-1",
            file=bio,
        )

        text = transcript.text.strip()
        duration = voice.duration
        preview = text[:60] + "..." if len(text) > 60 else text
        msg_log.info('Transcribed: "%s" (%ds)', preview, duration)
        return text

    except Exception:
        msg_log.exception("Failed to transcribe voice note")
        return None


async def _download_photos(bot: Bot, message: Message) -> list[dict]:
    """Download photos from a Telegram message and return Anthropic image content blocks.

    Handles two photo sources:
    - message.photo: compressed Telegram photos (always JPEG). Takes [-1] for highest res.
    - message.document: uncompressed image files sent as documents. Skips files >5MB.

    Returns a list of dicts matching the Anthropic image content block format:
    {"type": "image", "source": {"type": "base64", "media_type": "...", "data": "..."}}
    """
    blocks = []

    try:
        if message.photo:
            # message.photo is a list of PhotoSize — take [-1] for largest resolution
            photo = message.photo[-1]
            file = await bot.get_file(photo.file_id)
            bio = io.BytesIO()
            await bot.download_file(file.file_path, bio)
            bio.seek(0)
            data = base64.b64encode(bio.read()).decode("utf-8")
            blocks.append(
                {
                    "type": "image",
                    "source": {
                        "type": "base64",
                        "media_type": "image/jpeg",
                        "data": data,
                    },
                }
            )

        elif (
            message.document
            and message.document.mime_type
            and message.document.mime_type.startswith("image/")
        ):
            doc = message.document
            # Skip files >5MB (Telegram compressed photos are always smaller)
            if doc.file_size and doc.file_size > 5 * 1024 * 1024:
                msg_log.warning(
                    "Document image too large (%d bytes), skipping", doc.file_size
                )
                return blocks
            file = await bot.get_file(doc.file_id)
            bio = io.BytesIO()
            await bot.download_file(file.file_path, bio)
            bio.seek(0)
            data = base64.b64encode(bio.read()).decode("utf-8")
            blocks.append(
                {
                    "type": "image",
                    "source": {
                        "type": "base64",
                        "media_type": doc.mime_type,
                        "data": data,
                    },
                }
            )

    except Exception:
        msg_log.exception("Failed to download photo")

    return blocks


async def _flush_message_buffer() -> None:
    """Process all buffered items as a single concatenated message.

    Called after the debounce timer fires. Concatenates all buffered
    text items and collects all photos, then routes the combined
    message to the appropriate orchestrator handler based on context:

    1. Agent topic (CC session from /new) -> orchestrator.handle_agent_topic_message
    2. Everything else (General topic) -> orchestrator.handle_general_message
    """
    global _message_buffer, _debounce_task

    if not _message_buffer or not _orchestrator:
        _message_buffer = []
        _debounce_task = None
        return

    items = _message_buffer
    _message_buffer = []
    _debounce_task = None

    # Use the last message as the "reply target" (for thread ID, reply, etc.)
    last_message = items[-1].message

    # Get the topic ID for routing
    topic_id = last_message.message_thread_id

    # Concatenate all texts
    texts = [item.text for item in items if item.text]
    combined_text = "\n".join(texts)

    # Collect all photos from buffered items (media groups batch naturally)
    all_photos = []
    for item in items:
        if item.photos:
            all_photos.extend(item.photos)

    if len(items) > 1:
        msg_log.info(
            "Batched %d messages (%d chars, %d photos)",
            len(items),
            len(combined_text),
            len(all_photos),
        )

    # === ROUTING ===

    # 1. Agent topic (CC agent session from /new)
    if topic_id and _orchestrator.sessions.get(str(topic_id)):
        await _orchestrator.handle_agent_topic_message(
            last_message,
            text_override=combined_text,
            photos=all_photos or None,
        )
    # 2. Everything else (General topic or unrecognized topic)
    else:
        await _orchestrator.handle_general_message(
            last_message,
            text_override=combined_text,
            photos=all_photos or None,
        )


def _enqueue_and_debounce(item: _BufferedItem) -> None:
    """Add an item to the buffer and reset the debounce timer.

    Each new message cancels the existing timer and starts a fresh
    1.5-second countdown. When no new messages arrive within the
    window, _flush_message_buffer fires and processes the batch.
    """
    global _message_buffer, _debounce_task

    _message_buffer.append(item)

    # Cancel existing debounce timer and start a new one
    if _debounce_task and not _debounce_task.done():
        _debounce_task.cancel()

    async def _debounce_fire():
        await asyncio.sleep(_DEBOUNCE_SECONDS)
        await _flush_message_buffer()

    _debounce_task = asyncio.create_task(_debounce_fire())


@router.message(Command("reboot"))
async def handle_reboot(message: Message) -> None:
    """Reboot the bot — kills process, launchd/systemd restarts it.

    Accepts from the configured group only. Useful when the bot
    gets into a bad state or after deploying code changes.
    """
    if not _config:
        return
    if message.chat.id != _config.group_id:
        return
    if not _is_authorized(message):
        return

    sys_log.info(
        "Reboot requested by %s",
        message.from_user.full_name if message.from_user else "Unknown",
    )
    await message.reply("Rebooting...")
    await asyncio.sleep(0.5)
    sys.exit(0)


@router.message()
async def handle_message(message: Message) -> None:
    """Route all incoming messages — batch rapid-fire text and voice messages.

    This is the single entry point for all non-command messages. It:
    1. Validates the message source (must be from the configured group)
    2. Checks authorization (owner lock)
    3. Handles voice notes (transcribe + enqueue)
    4. Handles photos (download as base64 + enqueue with caption)
    5. Handles text (enqueue directly)

    All items go through the debounce buffer before routing.
    """
    if not _orchestrator or not _config:
        return

    # Only accept messages from the configured group
    if message.chat.id != _config.group_id:
        return

    if not _is_authorized(message):
        return

    # Voice note -> transcribe and enqueue
    if message.voice:
        duration = message.voice.duration if message.voice else 0
        msg_log.info("Voice note received (%ds) — transcribing...", duration)
        await message.reply("Transcribing voice note...")
        text = await _transcribe_voice(_orchestrator.bot, message)
        if text:
            _enqueue_and_debounce(_BufferedItem(message=message, text=text))
        else:
            await message.reply(
                "Could not transcribe voice note. Check OPENAI_API_KEY in .env."
            )
        return

    # Photo message -> download and enqueue (with caption as text)
    if message.photo or (
        message.document
        and message.document.mime_type
        and message.document.mime_type.startswith("image/")
    ):
        photos = await _download_photos(_orchestrator.bot, message)
        if photos:
            text = message.caption or message.text or ""
            msg_log.info(
                "Photo received (%d image(s), caption=%d chars)",
                len(photos),
                len(text),
            )
            _enqueue_and_debounce(
                _BufferedItem(message=message, text=text, photos=photos)
            )
        return

    # Text message -> enqueue
    if not message.text:
        return

    username = message.from_user.full_name if message.from_user else "Unknown"
    preview = message.text[:60] + "..." if len(message.text) > 60 else message.text
    msg_log.info('Received: "%s" (%s)', preview, username)

    _enqueue_and_debounce(_BufferedItem(message=message, text=message.text))
