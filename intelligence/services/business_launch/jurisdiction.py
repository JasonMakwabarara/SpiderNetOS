"""Jurisdiction checklists for the business-launch pack (UK, South Africa, Zimbabwe).

Reads ``packages/feature-packs/business-launch/jurisdictions/<code>.yaml`` and
selects the checklist items that apply to a founder's structure and answers,
turning deadlines ``relative_to: incorporation`` into calendar dates when the
incorporation date is known. Mirrors ``App\\Services\\Launch\\JurisdictionPack``
on the Laravel side.

Selection rules
* ``applies_to`` lists structures (``sole_trader``, ``partnership``,
  ``company``) or ``all``. An unknown structure keeps structure-specific items
  but marks them ``conditional``.
* ``trigger`` (``hires``, ``handles_personal_data``, ``regulated_activity``,
  ``vat_threshold``) keeps the item when the profile says True, drops it when
  False and marks it ``conditional`` when unknown (None).
* The file's ``valid_as_of`` older than ``review_after_days`` (default 180)
  adds an awareness item: the rules may have changed.

Not legal or financial advice.
"""

from __future__ import annotations

import calendar
import re
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any, Mapping

import yaml

from . import DISCLAIMER, pack_dir

__all__ = [
    "JurisdictionError",
    "STRUCTURES",
    "TRIGGERS",
    "add_business_days",
    "add_months",
    "available_codes",
    "checklist",
    "due_date",
    "load",
]

STRUCTURES = ("sole_trader", "partnership", "company")
TRIGGERS = ("hires", "handles_personal_data", "regulated_activity", "vat_threshold")
OWNERS = ("founder", "atlas", "advisor")
DEFAULT_REVIEW_AFTER_DAYS = 180
_CODE_RE = re.compile(r"^[a-z]{2}$")


class JurisdictionError(ValueError):
    """Unknown or malformed jurisdiction."""


def _dir(pack: str | Path | None) -> Path:
    return (Path(pack) if pack else pack_dir()) / "jurisdictions"


def available_codes(pack: str | Path | None = None) -> list[str]:
    folder = _dir(pack)
    if not folder.is_dir():
        return []
    return sorted(p.stem for p in folder.glob("*.yaml") if _CODE_RE.match(p.stem))


def _as_date(value: Any) -> date | None:
    if value is None or value == "":
        return None
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    try:
        return date.fromisoformat(str(value)[:10])
    except ValueError as exc:
        raise JurisdictionError(f"Not an ISO date: {value!r}") from exc


def load(code: str, pack: str | Path | None = None) -> dict[str, Any]:
    code = str(code or "").strip().lower()
    if not _CODE_RE.match(code):
        raise JurisdictionError(f"Invalid jurisdiction code: {code!r}")
    path = _dir(pack) / f"{code}.yaml"
    if not path.is_file():
        raise JurisdictionError(f"Unknown jurisdiction: {code}")
    with open(path, encoding="utf-8") as handle:
        data = yaml.safe_load(handle)
    if not isinstance(data, dict) or data.get("code") != code:
        raise JurisdictionError(f"Malformed jurisdiction file: {path.name}")
    for item in data.get("checklist", []) or []:
        if item.get("owner") not in OWNERS:
            raise JurisdictionError(f"{code}: checklist item {item.get('id')!r} has invalid owner {item.get('owner')!r}")
    return data


def add_months(start: date, months: int) -> date:
    """Calendar months, clamping to the last day (31 Jan + 1 month → 28/29 Feb)."""
    index = start.month - 1 + months
    year, month = start.year + index // 12, index % 12 + 1
    return date(year, month, min(start.day, calendar.monthrange(year, month)[1]))


def add_business_days(start: date, days: int) -> date:
    """Monday–Friday only; public holidays are not modelled (the rule text says so)."""
    current, remaining = start, days
    while remaining > 0:
        current += timedelta(days=1)
        if current.weekday() < 5:
            remaining -= 1
    return current


def due_date(deadline: Mapping[str, Any], incorporated_on: date | None) -> date | None:
    """Only ``relative_to: incorporation`` deadlines get a date; others need facts we don't know."""
    if incorporated_on is None or deadline.get("relative_to") != "incorporation":
        return None
    offset = deadline.get("offset") or {}
    result = add_months(incorporated_on, int(offset.get("months", 0) or 0))
    result += timedelta(days=int(offset.get("days", 0) or 0))
    return add_business_days(result, int(offset.get("business_days", 0) or 0))


def _flag(value: Any) -> bool | None:
    if value is None or value == "":
        return None
    if isinstance(value, bool):
        return value
    text = str(value).strip().lower()
    if text in ("1", "true", "yes", "y", "on"):
        return True
    if text in ("0", "false", "no", "n", "off"):
        return False
    return None


def _profile(profile: Mapping[str, Any] | None) -> dict[str, Any]:
    profile = dict(profile or {})
    structure = str(profile.get("structure") or "").strip().lower() or None
    if structure not in STRUCTURES:
        structure = None
    out: dict[str, Any] = {"structure": structure}
    for trigger in TRIGGERS:
        out[trigger] = _flag(profile.get(trigger))
    return out


def checklist(
    code: str,
    *,
    incorporated_on: Any = None,
    profile: Mapping[str, Any] | None = None,
    today: Any = None,
    pack: str | Path | None = None,
) -> dict[str, Any]:
    data = load(code, pack)
    incorporated = _as_date(incorporated_on)
    today_date = _as_date(today) or date.today()
    facts = _profile(profile)
    deadlines = {d["id"]: d for d in data.get("deadlines", []) or []}

    items: list[dict[str, Any]] = []
    for item in data.get("checklist", []) or []:
        applies = [str(s) for s in (item.get("applies_to") or ["all"])]
        conditional = False
        if "all" not in applies:
            if facts["structure"] is None:
                conditional = True
            elif facts["structure"] not in applies:
                continue
        trigger = item.get("trigger")
        if trigger:
            state = facts.get(trigger)
            if state is False:
                continue
            if state is None:
                conditional = True

        deadline = deadlines.get(item.get("deadline")) if item.get("deadline") else None
        due = due_date(deadline, incorporated) if deadline else None
        items.append({
            "id": item["id"],
            "title": item["title"],
            "detail": item.get("detail"),
            "owner": item["owner"],
            "category": item.get("category", "registration"),
            "severity": item.get("severity") or ("awareness" if conditional else "action_needed"),
            "conditional": conditional,
            "trigger": trigger,
            "applies_to": applies,
            "confidence": item.get("confidence", "medium"),
            "links": list(item.get("links") or []),
            "deadline_id": item.get("deadline"),
            "due_on": due.isoformat() if due else None,
            "rule": deadline.get("rule") if deadline else None,
            "recurring": deadline.get("recurring") if deadline else None,
        })

    valid_as_of = _as_date(data.get("valid_as_of"))
    review_after = int(data.get("review_after_days") or DEFAULT_REVIEW_AFTER_DAYS)
    days_since = (today_date - valid_as_of).days if valid_as_of else None
    stale = days_since is None or days_since > review_after

    awareness: list[dict[str, Any]] = []
    if stale:
        awareness.append({
            "id": f"{data['code']}_rules_may_have_changed",
            "title": f"These {data.get('name', data['code'])} rules were last checked on {valid_as_of.isoformat() if valid_as_of else 'an unknown date'} — they may have changed",
            "owner": "atlas",
            "category": "awareness",
            "severity": "awareness",
        })

    registration = data.get("registration") or {}
    counts = {owner: sum(1 for i in items if i["owner"] == owner) for owner in OWNERS}
    return {
        "code": data["code"],
        "name": data.get("name"),
        "currency": data.get("currency"),
        "valid_as_of": valid_as_of.isoformat() if valid_as_of else None,
        "review_after_days": review_after,
        "days_since_valid": days_since,
        "stale": stale,
        "incorporated_on": incorporated.isoformat() if incorporated else None,
        "profile": facts,
        "registration": {
            "body": registration.get("body"),
            "website": registration.get("website"),
            "api_availability": (registration.get("api") or {}).get("availability"),
            "api_name": (registration.get("api") or {}).get("name"),
            "identity_verification": registration.get("identity_verification"),
            "atlas_may": list(registration.get("atlas_may") or []),
            "atlas_never": list(registration.get("atlas_never") or []),
        },
        "tax": data.get("tax") or {},
        "items": items,
        "awareness": awareness,
        "counts": counts,
        "disclaimer": f"{DISCLAIMER} {data.get('disclaimer', '')}".strip() if DISCLAIMER not in str(data.get("disclaimer", "")) else data["disclaimer"],
    }
