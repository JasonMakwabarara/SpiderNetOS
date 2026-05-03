"""Markdown to Telegram HTML conversion and text cleanup utilities."""

import logging
import re

logger = logging.getLogger(__name__)


def md_to_telegram_html(text: str) -> str:
    """Convert markdown text to Telegram-compatible HTML.

    Telegram supports: <b>, <i>, <code>, <pre>, <a href="">.
    Headers become bold lines, bullets become bullet characters.
    """
    if not text:
        return text

    lines = text.split("\n")
    result_lines = []
    in_code_block = False
    code_block_lines = []

    for line in lines:
        # Handle fenced code blocks
        if line.strip().startswith("```"):
            if in_code_block:
                # Closing code block
                code_content = "\n".join(code_block_lines)
                result_lines.append(f"<pre>{_escape_html(code_content)}</pre>")
                code_block_lines = []
                in_code_block = False
            else:
                in_code_block = True
            continue

        if in_code_block:
            code_block_lines.append(line)
            continue

        # Process non-code-block lines
        line = _convert_line(line)
        result_lines.append(line)

    # Handle unclosed code block
    if in_code_block and code_block_lines:
        code_content = "\n".join(code_block_lines)
        result_lines.append(f"<pre>{_escape_html(code_content)}</pre>")

    return "\n".join(result_lines)


def _convert_line(line: str) -> str:
    """Convert a single markdown line to Telegram HTML."""
    stripped = line.strip()

    # Headers -> bold
    header_match = re.match(r"^(#{1,6})\s+(.+)$", stripped)
    if header_match:
        header_text = header_match.group(2)
        header_text = _convert_inline(header_text)
        return f"\n<b>{header_text}</b>"

    # Horizontal rules
    if re.match(r"^[-*_]{3,}\s*$", stripped):
        return "---"

    # Bullet points: - or * at start
    bullet_match = re.match(r"^(\s*)[-*]\s+(.+)$", line)
    if bullet_match:
        indent = bullet_match.group(1)
        content = _convert_inline(bullet_match.group(2))
        return f"{indent}\u2022 {content}"

    # Numbered lists — keep as-is but convert inline
    num_match = re.match(r"^(\s*)(\d+\.)\s+(.+)$", line)
    if num_match:
        indent = num_match.group(1)
        number = num_match.group(2)
        content = _convert_inline(num_match.group(3))
        return f"{indent}{number} {content}"

    # Regular line — convert inline formatting
    return _convert_inline(line)


def _convert_inline(text: str) -> str:
    """Convert inline markdown formatting to Telegram HTML."""
    # Escape HTML entities first (but preserve what we'll add)
    text = _escape_html(text)

    # Inline code (must come before bold/italic to avoid conflicts)
    text = re.sub(r"`([^`]+)`", r"<code>\1</code>", text)

    # Bold + italic (***text***)
    text = re.sub(r"\*\*\*(.+?)\*\*\*", r"<b><i>\1</i></b>", text)

    # Bold (**text**)
    text = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", text)

    # Italic (*text*) — but not inside already-converted tags
    text = re.sub(r"(?<![<])\*(.+?)\*(?![>])", r"<i>\1</i>", text)

    # Links [text](url)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<a href="\2">\1</a>', text)

    return text


def _escape_html(text: str) -> str:
    """Escape HTML special characters for Telegram."""
    text = text.replace("&", "&amp;")
    text = text.replace("<", "&lt;")
    text = text.replace(">", "&gt;")
    return text


def split_summary_and_report(text: str) -> tuple[str, str]:
    """Split agent output into summary and full report.

    Looks for '## Summary' section. Returns (summary, full_text).
    If no summary section found, generates a brief one from the first paragraph.
    """
    if not text:
        return ("No output.", text)

    # Look for ## Summary section
    match = re.search(r"^## Summary\s*\n(.*?)(?=\n## |\Z)", text, re.MULTILINE | re.DOTALL)
    if match:
        summary = match.group(1).strip()
        return (summary, text)

    # Fallback: use first paragraph as summary
    paragraphs = text.split("\n\n")
    summary = paragraphs[0].strip() if paragraphs else text[:500]
    # Limit summary length
    if len(summary) > 600:
        summary = summary[:500] + "..."
    return (summary, text)


def extract_chart_paths(text: str) -> list[tuple[str, str]]:
    """Extract chart image references from markdown output.

    Looks for ![title](outputs/charts/filename.png) patterns.
    Returns list of (title, file_path) tuples.
    """
    pattern = r"!\[([^\]]*)\]\((outputs/charts/[^\)]+\.png)\)"
    return re.findall(pattern, text)


def extract_image_paths(text: str) -> list[tuple[str, str]]:
    """Extract image references from markdown output.

    Catches outputs/charts/*.png AND outputs/images/*.png and .jpg/.jpeg.
    Returns list of (title, file_path) tuples.
    """
    pattern = r"!\[([^\]]*)\]\((outputs/(?:charts|images)/[^\)]+\.(?:png|jpg|jpeg))\)"
    return re.findall(pattern, text)


# ── Table Parsing (inline) ───────────────────────────────────────────────────


def _parse_markdown_table(table_str: str) -> tuple[list[str], list[list[str]]]:
    """Parse a markdown table string into headers and rows.

    Returns (headers, rows) where headers is a list of column names
    and rows is a list of lists of cell values.
    """
    lines = [l.strip() for l in table_str.strip().split("\n") if l.strip()]
    if len(lines) < 2:
        return [], []

    def split_row(line: str) -> list[str]:
        line = line.strip()
        if line.startswith("|"):
            line = line[1:]
        if line.endswith("|"):
            line = line[:-1]
        return [cell.strip() for cell in line.split("|")]

    headers = split_row(lines[0])

    # Find separator row (contains dashes/colons)
    sep_idx = 1
    for i, line in enumerate(lines[1:], 1):
        if re.match(r"^\|?\s*[-:\s|]+\s*\|?$", line):
            sep_idx = i
            break

    rows = []
    for line in lines[sep_idx + 1:]:
        if re.match(r"^\|?\s*[-:\s|]+\s*\|?$", line):
            continue
        cells = split_row(line)
        while len(cells) < len(headers):
            cells.append("")
        rows.append(cells[:len(headers)])

    return headers, rows


# Regex to match a markdown table block
_TABLE_PATTERN = re.compile(
    r"((?:^|\n)"
    r"(?:\|[^\n]+\|\s*\n)"
    r"(?:\|[\s:]*-[-:\s|]*\|\s*\n)"
    r"(?:\|[^\n]+\|\s*\n)*"
    r"(?:\|[^\n]+\|))"
)


def _extract_and_split_on_tables(text: str) -> list[tuple[str, str]]:
    """Split text into ordered segments of text and tables.

    Returns a list of (segment_type, content) tuples where segment_type
    is "text" or "table". Preserves reading order.
    """
    if not text:
        return [("text", "")]

    segments = []
    last_end = 0

    for match in _TABLE_PATTERN.finditer(text):
        start = match.start()
        table_str = match.group(1)
        if table_str.startswith("\n"):
            start += 1
            table_str = table_str[1:]

        before = text[last_end:start]
        if before.strip():
            segments.append(("text", before))

        segments.append(("table", table_str))
        last_end = match.end()

    after = text[last_end:]
    if after.strip():
        segments.append(("text", after))

    if not segments:
        return [("text", text)]

    return segments


def _table_to_pre(table_markdown: str) -> str:
    """Convert a markdown table to a <pre> monospace block for Telegram."""
    headers, rows = _parse_markdown_table(table_markdown)
    if not headers:
        return _escape_html(table_markdown)

    # Calculate column widths
    col_widths = [len(h) for h in headers]
    for row in rows:
        for i, cell in enumerate(row):
            if i < len(col_widths):
                col_widths[i] = max(col_widths[i], len(cell))

    def format_row(cells: list[str]) -> str:
        parts = []
        for i, cell in enumerate(cells):
            width = col_widths[i] if i < len(col_widths) else len(cell)
            parts.append(cell.ljust(width))
        return " | ".join(parts)

    lines = [format_row(headers)]
    lines.append("-+-".join("-" * w for w in col_widths))
    for row in rows:
        lines.append(format_row(row))

    return "<pre>" + _escape_html("\n".join(lines)) + "</pre>"


def prepare_segments_for_delivery(text: str) -> list[tuple[str, "str | bytes"]]:
    """Prepare agent text for Telegram delivery with table support.

    Splits text into ordered segments. Tables are rendered as <pre>
    monospace blocks. Text segments are converted to Telegram HTML.

    Returns list of ("text", html_string) tuples ready for send_segments().
    """
    raw_segments = _extract_and_split_on_tables(text)
    result = []

    for seg_type, content in raw_segments:
        if seg_type == "text":
            html = md_to_telegram_html(content)
            if html.strip():
                result.append(("text", html))
        elif seg_type == "table":
            pre_html = _table_to_pre(content)
            # Merge with previous text segment if possible
            if result and result[-1][0] == "text":
                result[-1] = ("text", result[-1][1] + "\n\n" + pre_html)
            else:
                result.append(("text", pre_html))

    if not result:
        return [("text", md_to_telegram_html(text))]

    return result


def clean_agent_output(text: str) -> str:
    """Strip common agent monologue patterns from output text.

    Used as a safety filter on the final deliverable text.
    Removes preamble lines like "Let me...", "I'll...", "Excellent!" etc.
    from the beginning of the text only.
    """
    if not text:
        return text

    lines = text.split("\n")
    cleaned = []

    # Patterns that indicate monologue rather than deliverable content
    monologue_patterns = [
        re.compile(r"^(Let me |I'll |I will |I need to |I should |I'm going to )", re.IGNORECASE),
        re.compile(r"^(Excellent!|Great!|Perfect!|Alright,|Sure,|OK,|Okay,)", re.IGNORECASE),
        re.compile(r"^(Now let me|First, let me|Let me start|I've read|I've reviewed)", re.IGNORECASE),
        re.compile(r"^(Here's what I found|Based on my analysis,|After reviewing)", re.IGNORECASE),
    ]

    # Only strip monologue from the beginning of the text
    past_preamble = False
    for line in lines:
        stripped = line.strip()
        if not past_preamble and stripped:
            if any(p.match(stripped) for p in monologue_patterns):
                continue
            past_preamble = True
        if past_preamble or not stripped:
            cleaned.append(line)

    result = "\n".join(cleaned).strip()
    return result if result else text
