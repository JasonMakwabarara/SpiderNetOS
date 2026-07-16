"""Markdown to PDF conversion for AI Command Bot deliverables.

Uses weasyprint and the Python markdown library to convert
agent output into professional PDF reports.
"""

import base64
import logging
import re
from datetime import datetime, timezone
from pathlib import Path

import markdown

logger = logging.getLogger(__name__)

TEMPLATE_DIR = Path(__file__).parent / "templates"
CSS_PATH = TEMPLATE_DIR / "report.css"

HTML_TEMPLATE = """\
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
{css}
</style>
</head>
<body>
<div class="report-header">
    <h1>{title}</h1>
    <p class="date">{date}</p>
</div>
{content}
</body>
</html>
"""


def _load_css() -> str:
    """Load the report CSS template."""
    try:
        return CSS_PATH.read_text()
    except FileNotFoundError:
        logger.warning("Report CSS not found at %s, using minimal styles", CSS_PATH)
        return "body { font-family: sans-serif; font-size: 11pt; line-height: 1.6; }"


def _render_html(markdown_text: str, title: str) -> str:
    """Convert markdown to a complete HTML document with styling."""
    css = _load_css()

    md = markdown.Markdown(extensions=["tables", "fenced_code", "toc", "nl2br"])
    html_content = md.convert(markdown_text)

    date_str = datetime.now(timezone.utc).strftime("%B %d, %Y")

    return HTML_TEMPLATE.format(
        css=css,
        title=title,
        date=date_str,
        content=html_content,
    )


def generate_pdf(markdown_text: str, title: str, output_path: str) -> str | None:
    """Generate a PDF file from markdown content.

    Returns the output path on success, None on failure.
    """
    try:
        from weasyprint import HTML as WeasyHTML
    except ImportError:
        logger.error("weasyprint not installed — cannot generate PDF")
        return None

    try:
        html = _render_html(markdown_text, title)
        WeasyHTML(string=html).write_pdf(output_path)
        logger.info("PDF generated: %s", output_path)
        return output_path
    except Exception:
        logger.exception("PDF generation failed")
        return None


def _embed_local_images(html: str, workspace_dir: str) -> str:
    """Replace local image src paths with base64 data URIs for PDF embedding."""
    def replace_img(match):
        src = match.group(1)
        full_path = Path(workspace_dir) / src
        if full_path.exists():
            img_bytes = full_path.read_bytes()
            b64 = base64.b64encode(img_bytes).decode()
            return f'src="data:image/png;base64,{b64}"'
        return match.group(0)

    return re.sub(r'src="(outputs/charts/[^"]+)"', replace_img, html)


def generate_pdf_with_charts(
    markdown_text: str, title: str, workspace_dir: str
) -> bytes | None:
    """Generate a PDF with embedded chart images and return as bytes.

    Chart image references (outputs/charts/*.png) are converted to
    base64 data URIs so the PDF is self-contained.
    """
    try:
        from weasyprint import HTML as WeasyHTML
    except ImportError:
        logger.error("weasyprint not installed — cannot generate PDF")
        return None

    try:
        html = _render_html(markdown_text, title)
        html = _embed_local_images(html, workspace_dir)
        return WeasyHTML(string=html).write_pdf()
    except Exception:
        logger.exception("PDF generation with charts failed")
        return None


def generate_pdf_bytes(markdown_text: str, title: str) -> bytes | None:
    """Generate a PDF and return as bytes (for Telegram BufferedInputFile).

    Returns bytes on success, None on failure.
    """
    try:
        from weasyprint import HTML as WeasyHTML
    except ImportError:
        logger.error("weasyprint not installed — cannot generate PDF")
        return None

    try:
        html = _render_html(markdown_text, title)
        return WeasyHTML(string=html).write_pdf()
    except Exception:
        logger.exception("PDF generation failed")
        return None
