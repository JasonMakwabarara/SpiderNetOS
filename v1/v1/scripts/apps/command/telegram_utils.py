"""Telegram message utilities for AI Command Bot."""

import logging
import re

from aiogram import Bot
from aiogram.types import BufferedInputFile

logger = logging.getLogger(__name__)


def split_message(text: str, max_length: int = 4096) -> list[str]:
    """Split text into chunks, breaking at newlines when possible.

    Telegram messages have a 4096 character limit. This splits at
    natural newline boundaries when possible, falling back to hard
    splits at max_length.
    """
    if len(text) <= max_length:
        return [text]

    chunks = []
    while text:
        if len(text) <= max_length:
            chunks.append(text)
            break

        split_pos = text.rfind("\n", 0, max_length)
        if split_pos == -1 or split_pos < max_length // 2:
            split_pos = max_length

        chunks.append(text[:split_pos])
        text = text[split_pos:].lstrip("\n")

    return chunks


def _has_html_tags(text: str) -> bool:
    """Check if text contains HTML tags (likely from our formatter)."""
    return bool(re.search(r"<(b|i|code|pre|a\s)", text))


async def send_long_message(
    bot: Bot,
    chat_id: int,
    text: str,
    message_thread_id: int | None = None,
    parse_mode: str | None = None,
) -> None:
    """Send a message that may exceed 4096 chars by splitting or sending as file.

    Auto-detects HTML content and sets parse_mode accordingly.
    Falls back to plain text if HTML parsing fails.
    Sends as file if content exceeds 6000 chars.
    """
    if not text or not text.strip():
        text = "(empty response)"

    # Auto-detect HTML if not explicitly set
    if parse_mode is None and _has_html_tags(text):
        parse_mode = "HTML"

    # Send as file if too long (lowered threshold for better Telegram UX)
    if len(text) > 6000:
        await send_as_file(bot, chat_id, text, "response.md", message_thread_id)
        return

    chunks = split_message(text)
    for chunk in chunks:
        try:
            await bot.send_message(
                chat_id=chat_id,
                text=chunk,
                message_thread_id=message_thread_id,
                parse_mode=parse_mode,
            )
        except Exception as e:
            if parse_mode == "HTML":
                # HTML parsing failed — retry as plain text
                logger.warning("HTML parse failed, falling back to plain text: %s", e)
                try:
                    await bot.send_message(
                        chat_id=chat_id,
                        text=chunk,
                        message_thread_id=message_thread_id,
                        parse_mode=None,
                    )
                except Exception:
                    logger.exception("Failed to send message even as plain text")
            else:
                logger.exception("Failed to send message")


async def send_message_inline(
    bot: Bot,
    chat_id: int,
    text: str,
    message_thread_id: int | None = None,
    parse_mode: str | None = None,
) -> None:
    """Send a message as inline text — NEVER falls back to a file.

    Splits into multiple Telegram messages if needed (4096 char limit each).
    Use this for 'message' format delivery where the user wants to read
    the text directly in chat, not download a file.
    """
    if not text or not text.strip():
        text = "(empty response)"

    if parse_mode is None and _has_html_tags(text):
        parse_mode = "HTML"

    chunks = split_message(text)
    for chunk in chunks:
        try:
            await bot.send_message(
                chat_id=chat_id,
                text=chunk,
                message_thread_id=message_thread_id,
                parse_mode=parse_mode,
            )
        except Exception as e:
            if parse_mode == "HTML":
                logger.warning("HTML parse failed, falling back to plain text: %s", e)
                try:
                    await bot.send_message(
                        chat_id=chat_id,
                        text=chunk,
                        message_thread_id=message_thread_id,
                        parse_mode=None,
                    )
                except Exception:
                    logger.exception("Failed to send message even as plain text")
            else:
                logger.exception("Failed to send message")


async def send_as_file(
    bot: Bot,
    chat_id: int,
    content: str,
    filename: str,
    message_thread_id: int | None = None,
    caption: str | None = None,
) -> None:
    """Send string content as a file attachment."""
    doc = BufferedInputFile(content.encode("utf-8"), filename=filename)
    await bot.send_document(
        chat_id=chat_id,
        document=doc,
        caption=caption or f"Response ({len(content)} chars)",
        message_thread_id=message_thread_id,
    )


async def send_photo_file(
    bot: Bot,
    chat_id: int,
    file_path: str,
    caption: str | None = None,
    message_thread_id: int | None = None,
) -> None:
    """Send a local image file as a Telegram photo.

    Falls back to sending as document if the photo fails (e.g. wrong format).
    """
    from pathlib import Path

    photo = BufferedInputFile(Path(file_path).read_bytes(), filename=Path(file_path).name)
    try:
        await bot.send_photo(
            chat_id=chat_id,
            photo=photo,
            caption=caption,
            message_thread_id=message_thread_id,
        )
    except Exception as e:
        if "wrong file identifier" in str(e).lower() or "photo_invalid" in str(e).lower():
            # Fall back to sending as document
            doc = BufferedInputFile(Path(file_path).read_bytes(), filename=Path(file_path).name)
            await bot.send_document(
                chat_id=chat_id,
                document=doc,
                caption=caption,
                message_thread_id=message_thread_id,
            )
        else:
            raise


async def send_photo_bytes(
    bot: Bot,
    chat_id: int,
    image_bytes: bytes,
    filename: str = "table.png",
    caption: str | None = None,
    message_thread_id: int | None = None,
) -> None:
    """Send in-memory image bytes as a Telegram photo.

    Falls back to sending as document if the photo fails.
    """
    photo = BufferedInputFile(image_bytes, filename=filename)
    try:
        await bot.send_photo(
            chat_id=chat_id,
            photo=photo,
            caption=caption,
            message_thread_id=message_thread_id,
        )
    except Exception as e:
        if "wrong file identifier" in str(e).lower() or "photo_invalid" in str(e).lower():
            doc = BufferedInputFile(image_bytes, filename=filename)
            await bot.send_document(
                chat_id=chat_id,
                document=doc,
                caption=caption,
                message_thread_id=message_thread_id,
            )
        else:
            raise


async def send_segments(
    bot: Bot,
    chat_id: int,
    segments: list[tuple[str, "str | bytes"]],
    message_thread_id: int | None = None,
    parse_mode: str | None = "HTML",
) -> None:
    """Deliver an ordered list of text and image segments to Telegram.

    Preserves reading order — sends text, then table image, then more text
    exactly as the agent structured the response.

    Segments are ("text", html_string) or ("image", png_bytes).
    """
    for seg_type, content in segments:
        if seg_type == "text":
            if not content or not content.strip():
                continue
            await send_message_inline(
                bot, chat_id, content,
                message_thread_id=message_thread_id,
                parse_mode=parse_mode,
            )
        elif seg_type == "image":
            try:
                await send_photo_bytes(
                    bot, chat_id, content,
                    message_thread_id=message_thread_id,
                )
            except Exception:
                logger.exception("Failed to send table image")


def truncate(text: str, max_length: int = 200) -> str:
    """Truncate text with ellipsis for previews."""
    if len(text) <= max_length:
        return text
    return text[: max_length - 3] + "..."


def format_duration(duration_ms: int) -> str:
    """Format a compact duration footer."""
    duration_s = duration_ms / 1000
    if duration_s >= 60:
        mins = int(duration_s // 60)
        secs = int(duration_s % 60)
        return f"{mins}m {secs}s"
    return f"{duration_s:.1f}s"
