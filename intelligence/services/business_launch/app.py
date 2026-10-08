"""FastAPI surface for the business-launch service (default port 9010).

Called by Laravel's ``App\\Services\\Launch\\BusinessLaunchService`` (config
``launch.service_url``). Internal-only: when ``BACKEND_INTERNAL_KEY`` is set,
every route except ``/health`` requires a matching ``X-Internal-Key`` header.

    POST /finance/model        assumptions → summary + monthly rows + xlsx (base64)
    POST /docgen/render        template + context → markdown (+ docx / pdf when available)
    POST /compliance/checklist jurisdiction + profile (+ incorporation date) → checklist
    GET  /health               liveness + which optional renderers are present

Run: ``python -m services.business_launch.app`` from ``intelligence/`` or
``uvicorn services.business_launch.app:app --port 9010``.

Not legal or financial advice.
"""

from __future__ import annotations

import hmac
import os
from datetime import date
from typing import Any, Optional

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from . import DISCLAIMER, PACK_ID, docgen, finance_model, jurisdiction

app = FastAPI(
    title="SpiderNetOS business-launch service",
    version="0.1.0",
    description="Deterministic finance model, document generation and jurisdiction checklists. " + DISCLAIMER,
)


def require_internal_key(x_internal_key: Optional[str] = Header(default=None)) -> None:
    expected = os.getenv("BACKEND_INTERNAL_KEY", "")
    if expected and not hmac.compare_digest(expected, x_internal_key or ""):
        raise HTTPException(status_code=401, detail="invalid internal key")


class FinanceModelRequest(BaseModel):
    assumptions: dict[str, Any]
    include_workbook: bool = True


class DocgenRequest(BaseModel):
    template: str = docgen.DEFAULT_TEMPLATE
    context: dict[str, Any] = Field(default_factory=dict)
    formats: list[str] = Field(default_factory=lambda: ["md"])


class ChecklistRequest(BaseModel):
    jurisdiction: str
    incorporated_on: Optional[date] = None
    profile: dict[str, Any] = Field(default_factory=dict)
    today: Optional[date] = None


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "status": "ok",
        "service": PACK_ID,
        "jurisdictions": jurisdiction.available_codes(),
        "capabilities": {"xlsx": _openpyxl_available(), **docgen.capabilities()},
        "disclaimer": DISCLAIMER,
    }


def _openpyxl_available() -> bool:
    import importlib.util

    return importlib.util.find_spec("openpyxl") is not None


@app.post("/finance/model", dependencies=[Depends(require_internal_key)])
def finance_model_endpoint(body: FinanceModelRequest) -> dict[str, Any]:
    try:
        return finance_model.run_model(body.assumptions, include_workbook=body.include_workbook)
    except finance_model.AssumptionError as exc:
        raise HTTPException(status_code=422, detail={"message": "Invalid assumptions", "errors": exc.errors}) from exc


@app.post("/docgen/render", dependencies=[Depends(require_internal_key)])
def docgen_endpoint(body: DocgenRequest) -> dict[str, Any]:
    try:
        return docgen.render(body.context, body.formats, body.template)
    except docgen.DocgenError as exc:
        raise HTTPException(status_code=422, detail={"message": str(exc)}) from exc


@app.post("/compliance/checklist", dependencies=[Depends(require_internal_key)])
def checklist_endpoint(body: ChecklistRequest) -> dict[str, Any]:
    try:
        return jurisdiction.checklist(
            body.jurisdiction,
            incorporated_on=body.incorporated_on,
            profile=body.profile,
            today=body.today,
        )
    except jurisdiction.JurisdictionError as exc:
        raise HTTPException(status_code=404, detail={"message": str(exc)}) from exc


if __name__ == "__main__":  # pragma: no cover
    import uvicorn

    uvicorn.run(app, host=os.getenv("BUSINESS_LAUNCH_HOST", "0.0.0.0"), port=int(os.getenv("BUSINESS_LAUNCH_PORT", "9010")))
