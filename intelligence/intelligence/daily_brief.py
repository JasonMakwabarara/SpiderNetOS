"""
daily_brief.py — SpiderNet OS v3.2

Generates OODA-structured daily briefs for a tenant by aggregating the
past 24 hours of event-log activity and producing an LLM-powered
executive summary.
"""

from __future__ import annotations

import datetime
from typing import Any

# --------------------------------------------------------------------------- #
#  Public entry point
# --------------------------------------------------------------------------- #


async def generate_brief(
    db_pool,
    llm_client,
    tenant_id: str,
    date: datetime.date | None = None,
) -> dict[str, Any]:
    """Generate a structured daily brief for *tenant_id*.

    Parameters
    ----------
    db_pool:
        An asyncpg-compatible connection pool (or any pool exposing
        ``async with pool.acquire() as conn``).
    llm_client:
        An object with an ``async chat(messages, **kw)`` method that returns
        an object with a ``.content`` string attribute (e.g. an OpenAI-style
        async client wrapper).
    tenant_id:
        The tenant to generate the brief for.
    date:
        The date the brief covers (defaults to today).  The window is
        ``[date - 24h, date)``.

    Returns
    -------
    dict
        A structured brief with keys: ``tenant_id``, ``date``,
        ``observe``, ``orient``, ``decide``, ``act``,
        ``executive_summary``, ``raw_stats``.
    """
    if date is None:
        date = datetime.date.today()

    window_end = datetime.datetime.combine(date, datetime.time.max)
    window_start = window_end - datetime.timedelta(hours=24)

    events = await _fetch_events(db_pool, tenant_id, window_start, window_end)
    stats = _aggregate_stats(events)

    observe = _build_observe(stats)
    orient = _build_orient(stats)
    decide = _build_decide(stats)
    act = _build_act(stats)

    executive_summary = await _generate_executive_summary(
        llm_client,
        tenant_id=tenant_id,
        date=date,
        observe=observe,
        orient=orient,
        decide=decide,
        act=act,
        stats=stats,
    )

    return {
        "tenant_id": tenant_id,
        "date": date.isoformat(),
        "observe": observe,
        "orient": orient,
        "decide": decide,
        "act": act,
        "executive_summary": executive_summary,
        "raw_stats": stats,
    }


# --------------------------------------------------------------------------- #
#  Data fetching
# --------------------------------------------------------------------------- #


async def _fetch_events(
    db_pool,
    tenant_id: str,
    window_start: datetime.datetime,
    window_end: datetime.datetime,
) -> list[dict[str, Any]]:
    """Return all event-log rows for *tenant_id* within the time window."""
    query = """
        SELECT id, tenant_id, event_type, payload, created_at
        FROM   event_log
        WHERE  tenant_id  = $1
          AND  created_at >= $2
          AND  created_at <  $3
        ORDER BY created_at ASC
    """
    async with db_pool.acquire() as conn:
        rows = await conn.fetch(query, tenant_id, window_start, window_end)

    return [dict(row) for row in rows]


# --------------------------------------------------------------------------- #
#  Aggregation
# --------------------------------------------------------------------------- #


def _aggregate_stats(events: list[dict[str, Any]]) -> dict[str, Any]:
    """Derive high-level statistics from raw events."""
    executions_started = 0
    executions_completed = 0
    executions_failed = 0
    total_cost = 0.0
    anomalies: list[dict[str, Any]] = []
    agents_used: set[str] = set()
    event_type_counts: dict[str, int] = {}

    for evt in events:
        etype = evt.get("event_type", "")
        payload = evt.get("payload") or {}

        # If payload is a string (JSON), decode it.
        if isinstance(payload, str):
            import json

            try:
                payload = json.loads(payload)
            except (json.JSONDecodeError, TypeError):
                payload = {}

        event_type_counts[etype] = event_type_counts.get(etype, 0) + 1

        if etype == "flow.execution_started":
            executions_started += 1
        elif etype == "flow.execution_completed":
            executions_completed += 1
        elif etype == "flow.execution_failed":
            executions_failed += 1
        elif etype == "cost.incurred":
            total_cost += float(payload.get("amount", 0))
        elif etype == "anomaly.detected":
            anomalies.append(
                {
                    "description": payload.get("description", "unknown"),
                    "severity": payload.get("severity", "info"),
                    "timestamp": str(evt.get("created_at", "")),
                }
            )

        # Track which agents were involved.
        agent = payload.get("agent") or payload.get("agent_id")
        if agent:
            agents_used.add(str(agent))

    return {
        "total_events": len(events),
        "executions_started": executions_started,
        "executions_completed": executions_completed,
        "executions_failed": executions_failed,
        "total_cost": round(total_cost, 4),
        "anomalies": anomalies,
        "anomaly_count": len(anomalies),
        "agents_used": sorted(agents_used),
        "event_type_counts": event_type_counts,
    }


# --------------------------------------------------------------------------- #
#  OODA section builders
# --------------------------------------------------------------------------- #


def _build_observe(stats: dict[str, Any]) -> dict[str, Any]:
    """Observe — raw facts about what happened."""
    return {
        "title": "Observe",
        "summary": (
            f"{stats['total_events']} events recorded. "
            f"{stats['executions_started']} executions started, "
            f"{stats['executions_completed']} completed, "
            f"{stats['executions_failed']} failed. "
            f"{stats['anomaly_count']} anomalies detected."
        ),
        "details": {
            "total_events": stats["total_events"],
            "executions_started": stats["executions_started"],
            "executions_completed": stats["executions_completed"],
            "executions_failed": stats["executions_failed"],
            "anomaly_count": stats["anomaly_count"],
            "event_type_breakdown": stats["event_type_counts"],
        },
    }


def _build_orient(stats: dict[str, Any]) -> dict[str, Any]:
    """Orient — interpretation and context."""
    failure_rate = 0.0
    if stats["executions_started"] > 0:
        failure_rate = round(
            stats["executions_failed"] / stats["executions_started"] * 100, 1
        )

    risk_level = "low"
    if failure_rate > 25:
        risk_level = "high"
    elif failure_rate > 10:
        risk_level = "medium"

    if stats["anomaly_count"] > 5:
        risk_level = "high"

    return {
        "title": "Orient",
        "summary": (
            f"Failure rate: {failure_rate}%. "
            f"Cost: ${stats['total_cost']:.2f}. "
            f"Risk level: {risk_level}. "
            f"Agents active: {len(stats['agents_used'])}."
        ),
        "details": {
            "failure_rate_pct": failure_rate,
            "total_cost": stats["total_cost"],
            "risk_level": risk_level,
            "agents_used": stats["agents_used"],
            "anomalies": stats["anomalies"],
        },
    }


def _build_decide(stats: dict[str, Any]) -> dict[str, Any]:
    """Decide — recommended actions based on observations."""
    recommendations: list[str] = []

    if stats["executions_failed"] > 0:
        recommendations.append(
            f"Investigate {stats['executions_failed']} failed execution(s) — "
            "check node-level errors and retry policies."
        )

    if stats["anomaly_count"] > 0:
        recommendations.append(
            f"Review {stats['anomaly_count']} anomaly alert(s) for potential "
            "security or performance issues."
        )

    if stats["total_cost"] > 100:
        recommendations.append(
            f"Cost exceeded $100 (${stats['total_cost']:.2f}). "
            "Consider reviewing agent budgets and execution frequency."
        )

    if not recommendations:
        recommendations.append("No immediate actions required — systems nominal.")

    return {
        "title": "Decide",
        "summary": "; ".join(recommendations),
        "details": {
            "recommendations": recommendations,
        },
    }


def _build_act(stats: dict[str, Any]) -> dict[str, Any]:
    """Act — concrete next steps."""
    actions: list[str] = []

    if stats["executions_failed"] > 0:
        actions.append("Open incident tickets for failed executions.")

    if stats["anomaly_count"] > 3:
        actions.append("Escalate anomaly cluster to the security team.")

    if stats["total_cost"] > 100:
        actions.append("Trigger budget-review approval gate for next cycle.")

    if not actions:
        actions.append("Continue monitoring — no action items.")

    return {
        "title": "Act",
        "summary": "; ".join(actions),
        "details": {
            "action_items": actions,
        },
    }


# --------------------------------------------------------------------------- #
#  LLM executive summary
# --------------------------------------------------------------------------- #


async def _generate_executive_summary(
    llm_client,
    *,
    tenant_id: str,
    date: datetime.date,
    observe: dict,
    orient: dict,
    decide: dict,
    act: dict,
    stats: dict,
) -> str:
    """Call the LLM to produce a concise executive summary paragraph."""
    system_prompt = (
        "You are an operations analyst for SpiderNet OS. "
        "Given the following OODA brief sections for a tenant's daily activity, "
        "write a concise 3-5 sentence executive summary suitable for a CTO or "
        "VP Engineering. Be factual and actionable."
    )

    user_prompt = (
        f"Tenant: {tenant_id}\n"
        f"Date: {date.isoformat()}\n\n"
        f"## Observe\n{observe['summary']}\n\n"
        f"## Orient\n{orient['summary']}\n\n"
        f"## Decide\n{decide['summary']}\n\n"
        f"## Act\n{act['summary']}\n\n"
        f"Raw stats: executions_started={stats['executions_started']}, "
        f"executions_completed={stats['executions_completed']}, "
        f"executions_failed={stats['executions_failed']}, "
        f"cost=${stats['total_cost']:.2f}, "
        f"anomalies={stats['anomaly_count']}, "
        f"agents={len(stats['agents_used'])}"
    )

    try:
        response = await llm_client.chat(
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            max_tokens=300,
            temperature=0.3,
        )
        return response.content.strip()
    except Exception as exc:  # noqa: BLE001
        return (
            f"[Auto-summary unavailable: {exc}] "
            f"{observe['summary']} {orient['summary']}"
        )
