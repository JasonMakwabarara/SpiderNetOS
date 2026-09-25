"""Business-launch service — "Atlas, I want to start a business" (plan D7 §5).

Deterministic helpers behind the business-launch feature pack:

* ``finance_model`` — assumptions → openpyxl workbook with live formulas plus a
  Python-computed summary (no LLM, ever).
* ``formula_eval`` — a small, dependency-free evaluator for the formulas the
  model writes, so the workbook is verified against the summary even where
  openpyxl is not installed.
* ``docgen`` — Jinja markdown → .docx (python-docx, when importable) → .pdf
  (weasyprint or pandoc, only when present).
* ``jurisdiction`` — UK / ZA / ZW checklists with deadlines relative to
  incorporation.
* ``app`` — the FastAPI surface on :9010 that Laravel's BusinessLaunchService
  calls.

Nothing in this package imports openpyxl, python-docx, weasyprint or pandoc at
import time. Every output carries ``DISCLAIMER``.
"""

from __future__ import annotations

import os
from pathlib import Path

__all__ = ["DISCLAIMER", "PACK_ID", "pack_dir"]

PACK_ID = "business-launch"

DISCLAIMER = "Not legal or financial advice."


def pack_dir() -> Path:
    """Location of ``packages/feature-packs/business-launch``.

    ``BUSINESS_LAUNCH_PACK_DIR`` wins (containers mount the pack there);
    otherwise the source tree next to ``intelligence/``.
    """
    override = os.getenv("BUSINESS_LAUNCH_PACK_DIR", "").strip()
    if override:
        return Path(override)
    return Path(__file__).resolve().parents[3] / "packages" / "feature-packs" / PACK_ID
