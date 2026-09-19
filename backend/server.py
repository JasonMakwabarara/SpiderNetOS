"""
SpiderNetOS Cockpit — FastAPI mock backend.

Purpose: provide realistic, static (or lightly stateful) responses for the
Vue cockpit while the real Laravel API is not available in this environment.
Every Laravel endpoint the cockpit calls is implemented here with mock data.

All routes are prefixed with /api so that Kubernetes ingress routes them
to this service (port 8001).

NOTE: This is NOT a complete re-implementation of the Laravel backend.
It returns just enough shape for the cockpit to render.
"""

from __future__ import annotations

import os

import random
import uuid
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, FastAPI, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

app = FastAPI(title="SpiderNetOS Cockpit — Mock API", version="1.0.0")

# Allowed origins come from CORS_ALLOW_ORIGINS (comma separated). The
# previous allow_origins=["*"] with allow_credentials=True is worse than it
# looks: Starlette echoes the caller's origin back, so any site could make
# credentialed calls. These planes are called server to server, where CORS
# does not apply at all, so the default only has to keep local browsers
# working.
_cors_origins = [
    o.strip()
    for o in os.getenv(
        'CORS_ALLOW_ORIGINS', 'http://localhost:3000,http://localhost:5173'
    ).split(',')
    if o.strip()
]

app.add_middleware(
    CORSMiddleware,
    allow_origins=_cors_origins,
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

api = APIRouter(prefix="/api")


# ─── Helpers ─────────────────────────────────────────────────────────────
def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def past(minutes: int = 0) -> str:
    return (datetime.now(timezone.utc) - timedelta(minutes=minutes)).isoformat()


CAP_BY_ROLE: Dict[str, List[str]] = {
    "user": ["self.flows", "self.agents", "self.usage"],
    "admin": [
        "tenant.view", "users.invite", "users.manage",
        "budget.edit", "audit.view", "copy.manage", "approvals.manage",
    ],
    "super_admin": [
        "platform.*", "tenant.*", "tenant.manage",
        "flag.write", "impersonate", "rollout.cutover",
        "ste.view", "audit.export", "copy.manage", "users.manage",
        "budget.edit", "audit.view", "approvals.manage",
    ],
}

ADMIN_EMAIL = "admin@spidernetos.com"
ADMIN_PASSWORD = "Zukaarimoto01!"


def _principal(email: str, role: str = "super_admin") -> Dict[str, Any]:
    return {
        "token": f"mock.{uuid.uuid4().hex}",
        "user": {
            "id": f"usr_{uuid.uuid4().hex[:8]}",
            "name": email.split("@")[0].replace(".", " ").title() or "SpiderNet Operator",
            "email": email,
            "role": role,
            "onboarding_completed_at": now_iso(),
        },
        "tenant": {
            "id": "tnt_acme",
            "name": "Acme Ops",
            "plan": "Ops Pro",
            "automation_level": "assisted",
        },
        "capabilities": CAP_BY_ROLE.get(role, []),
        "first_login": False,
    }


# ─── Health ──────────────────────────────────────────────────────────────
@api.get("/health")
async def health():
    return {"status": "ok", "service": "spidernetos-cockpit-mock", "time": now_iso()}


# ─── Auth ────────────────────────────────────────────────────────────────
class LoginBody(BaseModel):
    email: str
    password: str
    role: Optional[str] = "super_admin"


class RegisterBody(BaseModel):
    name: str
    email: str
    password: str
    tenant_name: Optional[str] = "New Workspace"


@api.post("/auth/login")
async def login(body: LoginBody):
    if not body.email or not body.password:
        raise HTTPException(status_code=422, detail="email and password are required")

    email = body.email.strip().lower()
    role = body.role if body.role in CAP_BY_ROLE else "super_admin"

    if email == ADMIN_EMAIL:
        if body.password != ADMIN_PASSWORD:
            raise HTTPException(status_code=401, detail="Invalid credentials")
        principal = _principal(ADMIN_EMAIL, "super_admin")
        principal["user"]["name"] = "SpiderNet Admin"
        principal["user"]["email"] = ADMIN_EMAIL
        principal["tenant"]["name"] = "SpiderNetOS"
        principal["tenant"]["id"] = "tnt_spidernetos"
        principal["capabilities"] = CAP_BY_ROLE["super_admin"]
        return principal

    return _principal(body.email, role)


@api.post("/auth/register")
async def register(body: RegisterBody):
    p = _principal(body.email, "super_admin")
    p["user"]["name"] = body.name
    p["user"]["onboarding_completed_at"] = None
    p["tenant"]["name"] = body.tenant_name or "New Workspace"
    p["first_login"] = True
    return p


@api.get("/auth/me")
async def me(request: Request):
    auth = request.headers.get("authorization", "")
    if not auth.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="unauthenticated")
    return {
        "user": {
            "id": "usr_demo",
            "name": "SpiderNet Operator",
            "email": "operator@acme.ops",
            "role": "super_admin",
            "onboarding_completed_at": now_iso(),
        },
        "tenant": {"id": "tnt_acme", "name": "Acme Ops", "plan": "Ops Pro", "automation_level": "assisted"},
        "capabilities": CAP_BY_ROLE["super_admin"],
    }


@api.post("/auth/logout")
async def logout():
    return {"ok": True}


@api.post("/auth/step-up")
async def step_up():
    return {"ok": True, "step_up_valid_until": (datetime.now(timezone.utc) + timedelta(minutes=5)).isoformat()}


# ─── Atlas ───────────────────────────────────────────────────────────────
_ATLAS_REPLIES = [
    "Queued a plan with 3 steps. Review in the task tracker.",
    "Drafted a flow — pending your approval before publish.",
    "Yesterday's outcomes: 142 automated decisions, 4 approvals, 1 trace escalation.",
    "Budget is at 38% of this month's cap. Projected month-end: 67%.",
    "No anomalies detected in the last 24h. Autopilot is green.",
]


class AtlasChatBody(BaseModel):
    message: str
    session_id: Optional[str] = None


@api.post("/atlas/chat")
async def atlas_chat(body: AtlasChatBody):
    suggestions = [
        {"id": "s1", "text": "Show this week's approvals", "command": "/approvals pending"},
        {"id": "s2", "text": "Summarize last flow run", "command": "/trace latest"},
    ]
    return {
        "session_id": body.session_id or f"sess_{uuid.uuid4().hex[:8]}",
        "message": random.choice(_ATLAS_REPLIES),
        "agent": "Atlas Core",
        "intent": "overview",
        "confidence": 0.92,
        "cost": round(random.uniform(0.002, 0.015), 4),
        "model": "claude-sonnet-4",
        "tokens_used": random.randint(120, 640),
        "execution_time_ms": random.randint(220, 780),
        "suggestions": suggestions,
    }


@api.post("/atlas/sessions")
async def atlas_new_session():
    sid = f"sess_{uuid.uuid4().hex[:8]}"
    return {
        "data": {
            "id": sid,
            "suggestions": [
                {"id": "b1", "text": "Review this week's outcomes", "command": "/status weekly"},
                {"id": "b2", "text": "Show pending approvals", "command": "/approvals pending"},
                {"id": "b3", "text": "Create a new flow", "command": "/create flow"},
            ],
        }
    }


@api.get("/atlas/sessions/{sid}")
async def atlas_get_session(sid: str):
    return {
        "data": {
            "id": sid,
            "messages": [],
            "tasks": [],
            "last_ast": None,
            "plan": None,
            "suggestions": [],
            "updated_at": now_iso(),
        }
    }


@api.post("/atlas/execute")
async def atlas_execute():
    return {"message": "Plan started.", "plan": None, "tasks": []}


@api.post("/atlas/cancel")
async def atlas_cancel():
    return {"message": "Plan cancelled.", "plan": None}


@api.post("/atlas/events")
async def atlas_events():
    return {"ok": True}


@api.post("/atlas/enhance-prompt")
async def atlas_enhance(body: Dict[str, Any]):
    raw = body.get("prompt", "")
    return {
        "enhanced": (
            f"As the Atlas orchestrator, perform the following with explicit "
            f"pre-conditions, observables, and rollback steps:\n\n{raw}"
        )
    }


# ─── Commands ────────────────────────────────────────────────────────────
@api.post("/command")
async def run_command(body: Dict[str, Any]):
    return {"ok": True, "received": body.get("command"), "result": "Command dispatched (mock)."}


@api.post("/command/enhance")
async def enhance_command(body: Dict[str, Any]):
    return {"enhanced": f"Refined: {body.get('command', '')}"}


# ─── Agents ──────────────────────────────────────────────────────────────
_AGENTS = [
    {"id": "ag_1", "name": "Lead Qualifier",     "template": "sales.qualifier",    "status": "active",    "model": "gpt-4o",            "runs_24h": 412, "cost_24h": 3.42, "updated_at": past(30)},
    {"id": "ag_2", "name": "Invoice Reconciler", "template": "finance.recon",      "status": "active",    "model": "claude-sonnet-4",    "runs_24h": 87,  "cost_24h": 1.18, "updated_at": past(92)},
    {"id": "ag_3", "name": "Ticket Triage",      "template": "support.triage",     "status": "paused",    "model": "gpt-4o-mini",        "runs_24h": 0,   "cost_24h": 0.0,  "updated_at": past(340)},
    {"id": "ag_4", "name": "Release Notes Writer","template": "eng.release_notes", "status": "draft",     "model": "claude-haiku-4",     "runs_24h": 0,   "cost_24h": 0.0,  "updated_at": past(1100)},
    {"id": "ag_5", "name": "Competitor Watcher", "template": "intel.watcher",      "status": "active",    "model": "gpt-4o",            "runs_24h": 24,  "cost_24h": 0.46, "updated_at": past(8)},
]

_AGENT_TEMPLATES = [
    {"id": "sales.qualifier", "name": "Sales — Lead Qualifier", "description": "Classifies inbound leads by ICP fit.", "icon": "📈"},
    {"id": "finance.recon",   "name": "Finance — Reconciler",   "description": "Reconciles invoices against ledger.",  "icon": "🧾"},
    {"id": "support.triage",  "name": "Support — Triage",       "description": "Routes tickets to the right queue.",   "icon": "🎫"},
    {"id": "intel.watcher",   "name": "Intel — Watcher",        "description": "Tracks competitor signals.",           "icon": "🛰"},
    {"id": "eng.release_notes","name": "Eng — Release Notes",   "description": "Drafts human changelogs from commits.","icon": "📝"},
]


@api.get("/agents")
async def list_agents():
    return {"data": _AGENTS}


@api.get("/agents/templates")
async def agent_templates():
    return {"data": _AGENT_TEMPLATES}


@api.get("/agents/graph/delegation")
async def agent_delegation_graph():
    return {"nodes": [{"id": a["id"], "label": a["name"]} for a in _AGENTS],
            "edges": [{"source": "ag_1", "target": "ag_3"}, {"source": "ag_5", "target": "ag_1"}]}


@api.get("/agents/{agent_id}")
async def get_agent(agent_id: str):
    for a in _AGENTS:
        if a["id"] == agent_id:
            return {"data": a}
    raise HTTPException(404, "agent not found")


@api.post("/agents")
async def create_agent(body: Dict[str, Any]):
    new = {
        "id": f"ag_{uuid.uuid4().hex[:4]}",
        "name": body.get("name", "Untitled Agent"),
        "template": body.get("template", "custom"),
        "status": "draft",
        "model": body.get("model", "gpt-4o-mini"),
        "runs_24h": 0, "cost_24h": 0.0,
        "updated_at": now_iso(),
    }
    return {"data": new}


@api.patch("/agents/{agent_id}/status")
async def toggle_agent(agent_id: str):
    return {"data": {"id": agent_id, "status": "active"}}


# ─── Flows ───────────────────────────────────────────────────────────────
_FLOWS = [
    {"id": "fl_1", "name": "Daily Pipeline Report",   "status": "published", "automation": "autonomous", "runs_24h": 1,   "last_run_at": past(120), "version": 4},
    {"id": "fl_2", "name": "High-Value Lead Routing", "status": "published", "automation": "assisted",   "runs_24h": 42,  "last_run_at": past(6),   "version": 11},
    {"id": "fl_3", "name": "Invoice Anomaly Sweep",   "status": "draft",     "automation": "manual",     "runs_24h": 0,   "last_run_at": None,      "version": 1},
    {"id": "fl_4", "name": "Support Escalation",      "status": "published", "automation": "assisted",   "runs_24h": 8,   "last_run_at": past(33),  "version": 6},
]


def _flow_detail(flow_id: str) -> Dict[str, Any]:
    base = next((f for f in _FLOWS if f["id"] == flow_id), None) or {
        "id": flow_id, "name": "New Flow", "status": "draft", "automation": "manual",
        "runs_24h": 0, "last_run_at": None, "version": 1,
    }
    return {
        **base,
        "nodes": [
            {"id": "n1", "type": "trigger", "label": "Webhook: new lead",     "x": 80,  "y": 60},
            {"id": "n2", "type": "agent",   "label": "Agent: Lead Qualifier", "x": 260, "y": 60},
            {"id": "n3", "type": "branch",  "label": "ICP fit?",              "x": 440, "y": 60},
            {"id": "n4", "type": "action",  "label": "Route → AE queue",      "x": 620, "y": 10},
            {"id": "n5", "type": "action",  "label": "Enrich + drip",         "x": 620, "y": 110},
            {"id": "n6", "type": "output",  "label": "Emit trace",            "x": 800, "y": 60},
        ],
        "edges": [
            {"from": "n1", "to": "n2"}, {"from": "n2", "to": "n3"},
            {"from": "n3", "to": "n4", "label": "yes"},
            {"from": "n3", "to": "n5", "label": "no"},
            {"from": "n4", "to": "n6"}, {"from": "n5", "to": "n6"},
        ],
        "validation_errors": [],
    }


@api.get("/flows")
async def list_flows():
    return {"data": _FLOWS}


@api.get("/flows/{flow_id}")
async def get_flow(flow_id: str):
    return {"data": _flow_detail(flow_id)}


@api.post("/flows")
async def create_flow(body: Dict[str, Any]):
    fid = f"fl_{uuid.uuid4().hex[:4]}"
    return {"data": _flow_detail(fid) | {"name": body.get("name", "Untitled Flow")}}


@api.put("/flows/{flow_id}")
async def update_flow(flow_id: str, body: Dict[str, Any]):
    return {"data": _flow_detail(flow_id) | {k: v for k, v in body.items() if v is not None}}


@api.delete("/flows/{flow_id}")
async def delete_flow(flow_id: str):
    return {"ok": True, "id": flow_id}


@api.post("/flows/{flow_id}/execute")
async def execute_flow(flow_id: str):
    return {"ok": True, "execution_id": f"exe_{uuid.uuid4().hex[:6]}", "flow_id": flow_id}


@api.post("/flows/{flow_id}/publish")
async def publish_flow(flow_id: str):
    return {"ok": True, "data": _flow_detail(flow_id) | {"status": "published"}}


@api.get("/flows/{flow_id}/executions")
async def flow_executions(flow_id: str):
    return {"data": [
        {"id": f"exe_{i}", "flow_id": flow_id, "status": random.choice(["completed", "completed", "running", "failed"]),
         "started_at": past(i * 8), "duration_ms": random.randint(400, 9000)}
        for i in range(1, 9)
    ]}


@api.get("/executions/{execution_id}")
async def execution_detail(execution_id: str):
    return {"data": {"id": execution_id, "status": "completed", "steps": [
        {"name": "trigger", "status": "completed", "duration_ms": 12},
        {"name": "qualify", "status": "completed", "duration_ms": 842, "cost": 0.0031},
        {"name": "route",   "status": "completed", "duration_ms": 54},
    ]}}


# ─── Approvals ───────────────────────────────────────────────────────────
_APPROVALS = [
    {"id": "apr_1", "title": "Publish flow: Invoice Anomaly Sweep",    "type": "flow.publish",       "risk": "medium",   "created_at": past(5),   "requested_by": "Atlas", "status": "pending",
     "summary": "Introduces autonomous write-back to accounting. Impact: 140 invoices/day.",
     "diff":    {"before": "manual", "after": "autonomous", "field": "automation_level"}},
    {"id": "apr_2", "title": "Raise monthly budget cap to $2,400",     "type": "budget.raise",       "risk": "low",      "created_at": past(62),  "requested_by": "Admin", "status": "pending",
     "summary": "Current cap $1,800; projected month-end $2,150.",
     "diff":    {"before": 1800, "after": 2400, "field": "budget.monthly_usd"}},
    {"id": "apr_3", "title": "Grant Users.manage to role=editor",       "type": "role.capability",    "risk": "high",     "created_at": past(180), "requested_by": "Super Admin", "status": "pending",
     "summary": "Editors would gain ability to invite + remove users.",
     "diff":    {"before": [], "after": ["users.manage"], "field": "role.editor.caps"}},
    {"id": "apr_4", "title": "Auto-escalate P0 tickets to on-call",     "type": "flow.publish",       "risk": "medium",   "created_at": past(1400),"requested_by": "Atlas", "status": "approved",
     "summary": "Escalates P0s within 3m of creation.",
     "diff":    {"before": "manual", "after": "assisted", "field": "escalation_mode"}},
]


@api.get("/approvals")
async def list_approvals():
    return {"data": _APPROVALS}


@api.post("/approvals/{apr_id}/approve")
async def approve(apr_id: str, body: Dict[str, Any]):
    return {"ok": True, "id": apr_id, "status": "approved", "reason": body.get("reason", "")}


@api.post("/approvals/{apr_id}/reject")
async def reject(apr_id: str, body: Dict[str, Any]):
    return {"ok": True, "id": apr_id, "status": "rejected", "reason": body.get("reason", "")}


# ─── Traces ──────────────────────────────────────────────────────────────
_TRACE_KINDS = ["agent.run", "flow.execute", "approval.request", "budget.alert", "stepup.challenge"]
_TRACES = [
    {
        "id": f"trc_{i:03d}",
        "kind": random.choice(_TRACE_KINDS),
        "status": random.choice(["ok", "ok", "warn", "error"]),
        "actor":  random.choice(["Atlas", "Lead Qualifier", "Invoice Reconciler", "System"]),
        "subject": random.choice(["flow:Daily Pipeline Report", "agent:Lead Qualifier", "budget", "role:editor"]),
        "duration_ms": random.randint(12, 4800),
        "cost_usd": round(random.uniform(0, 0.04), 4),
        "created_at": past(i * 3 + random.randint(0, 2)),
        "metadata": {"model": random.choice(["gpt-4o", "claude-sonnet-4", "gpt-4o-mini"]), "tokens": random.randint(60, 4000)},
    }
    for i in range(60)
]


@api.get("/traces")
async def list_traces():
    return {"data": _TRACES}


@api.get("/traces/{trace_id}")
async def trace_detail(trace_id: str):
    t = next((t for t in _TRACES if t["id"] == trace_id), None)
    if not t:
        raise HTTPException(404, "not found")
    return {"data": t | {"events": [
        {"ts": past(0), "level": "info",  "msg": "start"},
        {"ts": past(0), "level": "info",  "msg": "llm.call model=gpt-4o tokens=420"},
        {"ts": past(0), "level": "warn",  "msg": "retry 1/2 (rate limit)"},
        {"ts": past(0), "level": "info",  "msg": "end ok"},
    ]}}


# ─── Share-a-Trace (public read-only) ────────────────────────────────────
import hashlib

_SHARED_TRACES: Dict[str, Dict[str, Any]] = {}
_SHARED_APPROVALS: Dict[str, Dict[str, Any]] = {}


@api.post("/traces/{trace_id}/share")
async def share_trace(trace_id: str):
    t = next((t for t in _TRACES if t["id"] == trace_id), None)
    if not t:
        raise HTTPException(404, "trace not found")
    token = hashlib.sha256(f"{trace_id}-{uuid.uuid4().hex}".encode()).hexdigest()[:24]
    _SHARED_TRACES[token] = {
        "trace_id": trace_id,
        "tenant_id": "tnt_acme",
        "created_at": now_iso(),
        "expires_at": (datetime.now(timezone.utc) + timedelta(days=7)).isoformat(),
    }
    return {
        "token": token,
        "expires_at": _SHARED_TRACES[token]["expires_at"],
        "share_path": f"/share/trace/{token}",
    }


@api.get("/public/traces/{token}")
async def public_trace(token: str):
    rec = _SHARED_TRACES.get(token)
    if not rec:
        raise HTTPException(404, "share not found or expired")
    t = next((t for t in _TRACES if t["id"] == rec["trace_id"]), None)
    if not t:
        raise HTTPException(404, "trace gone")
    return {"data": t | {
        "events": [
            {"ts": past(0), "level": "info",  "msg": "start"},
            {"ts": past(0), "level": "info",  "msg": "llm.call model=gpt-4o tokens=420"},
            {"ts": past(0), "level": "warn",  "msg": "retry 1/2 (rate limit)"},
            {"ts": past(0), "level": "info",  "msg": "end ok"},
        ],
        "tenant_name": "Acme Ops",
        "shared_at": rec["created_at"],
        "expires_at": rec["expires_at"],
    }}


# ─── Share-an-Approval (public read-only) ────────────────────────────────
@api.post("/approvals/{apr_id}/share")
async def share_approval(apr_id: str):
    a = next((a for a in _APPROVALS if a["id"] == apr_id), None)
    if not a:
        raise HTTPException(404, "approval not found")
    token = hashlib.sha256(f"apr-{apr_id}-{uuid.uuid4().hex}".encode()).hexdigest()[:24]
    _SHARED_APPROVALS[token] = {
        "approval_id": apr_id,
        "tenant_id": "tnt_acme",
        "created_at": now_iso(),
        "expires_at": (datetime.now(timezone.utc) + timedelta(days=7)).isoformat(),
    }
    return {
        "token": token,
        "expires_at": _SHARED_APPROVALS[token]["expires_at"],
        "share_path": f"/share/approval/{token}",
    }


@api.get("/public/approvals/{token}")
async def public_approval(token: str):
    rec = _SHARED_APPROVALS.get(token)
    if not rec:
        raise HTTPException(404, "share not found or expired")
    a = next((a for a in _APPROVALS if a["id"] == rec["approval_id"]), None)
    if not a:
        raise HTTPException(404, "approval gone")
    return {"data": a | {
        "tenant_name": "Acme Ops",
        "shared_at": rec["created_at"],
        "expires_at": rec["expires_at"],
    }}


# ─── Intelligence ────────────────────────────────────────────────────────
@api.get("/intelligence/workers")
async def intel_workers():
    return {"data": [
        {"id": "w_1", "name": "Opportunity Scorer",  "status": "running", "queue": 12, "last_run": past(2),  "throughput_min": 48},
        {"id": "w_2", "name": "Churn Predictor",     "status": "idle",    "queue": 0,  "last_run": past(42), "throughput_min": 0},
        {"id": "w_3", "name": "Anomaly Detector",    "status": "running", "queue": 3,  "last_run": past(1),  "throughput_min": 16},
        {"id": "w_4", "name": "Lifecycle Summarizer","status": "failed",  "queue": 9,  "last_run": past(120),"throughput_min": 0},
    ]}


# ─── Memory ──────────────────────────────────────────────────────────────
@api.get("/memory")
async def memory_list():
    return {"data": [
        {"id": f"mem_{i}", "kind": random.choice(["doc", "fact", "skill", "profile"]),
         "title": random.choice(["ICP v3", "Refund SOP", "Tier-1 response templates", "Champion profiles", "Release cadence"]),
         "tokens": random.randint(120, 4800),
         "updated_at": past(i * 15)}
        for i in range(14)
    ]}


# ─── Usage / Budget ──────────────────────────────────────────────────────
@api.get("/usage/budget")
async def usage_budget():
    return {"data": {
        "monthly_limit": 1800.0,
        "monthly_cap_usd": 1800.0,
        "month_to_date_usd": 683.12,
        "daily_limit": 95.0,
        "daily_cap_usd": 95.0,
        "today_usd": 23.08,
        "alert_threshold": 0.8,
        "action_at_limit": "degrade",
        "near_limit": False,
        "degraded": False,
    }}


@api.put("/usage/budget")
async def update_budget(body: Dict[str, Any]):
    return {"data": {
        "monthly_limit": float(body.get("monthly_limit", 1800.0)),
        "daily_limit": float(body.get("daily_limit", 95.0)),
        "alert_threshold": float(body.get("alert_threshold", 0.8)),
        "action_at_limit": body.get("action_at_limit", "degrade"),
        "near_limit": False,
        "degraded": False,
    }}


@api.get("/usage/current")
async def usage_current():
    return {"data": {
        "daily": 23.08, "weekly": 142.66, "monthly": 683.12,
        "tokens_today": 1_420_000, "requests_today": 18_440,
    }}


@api.get("/usage/daily")
async def usage_daily(days: int = 30):
    breakdown = []
    totals = []
    for i in range(days):
        d = (datetime.now(timezone.utc) - timedelta(days=(days - i))).strftime("%Y-%m-%d")
        totals.append({"date": d, "total_cost": round(18 + 12 * random.random(), 2),
                       "total_tokens": random.randint(600_000, 2_400_000),
                       "total_calls": random.randint(6000, 22000)})
        for rt in ("llm", "tools", "storage"):
            breakdown.append({"date": d, "resource_type": rt,
                              "total_cost": round(random.uniform(1, 12), 2),
                              "total_tokens": random.randint(100_000, 800_000),
                              "total_calls": random.randint(500, 8000)})
    return {"daily_usage": {"daily_totals": totals, "breakdown": breakdown}}


@api.get("/usage/monthly")
async def usage_monthly(months: int = 12):
    totals = []
    breakdown = []
    base = datetime.now(timezone.utc).replace(day=1)
    for i in range(months):
        dt = (base - timedelta(days=30 * (months - i))).strftime("%Y-%m")
        totals.append({"date": dt, "total_cost": round(380 + 240 * random.random(), 2),
                       "total_tokens": random.randint(20_000_000, 60_000_000),
                       "total_calls": random.randint(180_000, 620_000)})
    return {"monthly_usage": {"monthly_totals": totals, "breakdown": breakdown}}


@api.get("/usage/series")
async def usage_series():
    days = 30
    return {"data": [
        {"date": (datetime.now(timezone.utc) - timedelta(days=(days - i))).strftime("%Y-%m-%d"),
         "cost": round(18 + 12 * random.random() + 6 * random.random(), 2),
         "requests": random.randint(6000, 22000),
         "tokens": random.randint(600_000, 2_400_000)}
        for i in range(days)
    ]}


@api.get("/usage/anomalies")
async def usage_anomalies():
    return {"data": [
        {"id": "a1", "date": past(60 * 26), "msg": "Spike: Lead Qualifier cost +42% above 7d baseline",   "severity": "warn"},
        {"id": "a2", "date": past(60 * 80), "msg": "Tokens/day crossed 2M for first time this month",     "severity": "info"},
    ]}


# ─── Admin ───────────────────────────────────────────────────────────────
@api.get("/admin/users")
async def admin_users():
    return {"data": [
        {"id": "u1", "name": "Maya Lin",    "email": "maya@acme.ops",   "role": "admin",       "last_seen": past(3),   "status": "active"},
        {"id": "u2", "name": "Noor Habib",  "email": "noor@acme.ops",   "role": "user",        "last_seen": past(42),  "status": "active"},
        {"id": "u3", "name": "Park Joon",   "email": "joon@acme.ops",   "role": "super_admin", "last_seen": past(8),   "status": "active"},
        {"id": "u4", "name": "Hana Svenson","email": "hana@acme.ops",   "role": "user",        "last_seen": past(2000),"status": "invited"},
    ]}


@api.get("/admin/audit")
async def admin_audit():
    kinds = ["login", "flow.publish", "budget.change", "role.grant", "agent.activate", "approval.approve"]
    return {"data": [
        {"id": f"aud_{i:03d}", "actor": random.choice(["maya@acme.ops", "joon@acme.ops", "atlas"]),
         "action": random.choice(kinds), "target": random.choice(["fl_1", "ag_2", "budget", "role:editor"]),
         "ts": past(i * 13), "ip": f"10.0.{random.randint(0, 9)}.{random.randint(10, 250)}"}
        for i in range(40)
    ]}


@api.get("/admin/copy/experiments")
async def admin_copy():
    return {"data": [
        {"id": "cx1", "arm": "control", "impressions": 12404, "ctr": 0.038, "winning": False,
         "text": "Run your business on autopilot."},
        {"id": "cx2", "arm": "variant_a", "impressions": 12510, "ctr": 0.046, "winning": False,
         "text": "The operator console for your autonomous business."},
        {"id": "cx3", "arm": "variant_b", "impressions": 12462, "ctr": 0.052, "winning": True,
         "text": "5 minutes a week. Thousands of decisions a day."},
    ]}


# ─── Platform ────────────────────────────────────────────────────────────
@api.get("/platform/overview")
async def platform_overview():
    return {
        "tenants": 42,
        "active_today": 31,
        "total_agents": 214,
        "total_flows": 188,
        "rollouts_active": 2,
        "feature_flags": 17,
    }


@api.get("/platform/feature-flags")
async def platform_flags():
    return {"data": [
        {"key": "atlas.enhance_prompt",   "on": True,  "rollout_pct": 100},
        {"key": "flows.dag_v2",           "on": True,  "rollout_pct": 80},
        {"key": "usage.v2",               "on": False, "rollout_pct": 25},
        {"key": "memory.semantic_search", "on": True,  "rollout_pct": 50},
        {"key": "approvals.auto_diff",    "on": True,  "rollout_pct": 100},
        {"key": "billing.crypto",         "on": False, "rollout_pct": 0},
    ]}


@api.post("/platform/feature-flags/{key}")
async def set_flag(key: str, body: Dict[str, Any]):
    return {"ok": True, "key": key, "on": body.get("on", False), "rollout_pct": body.get("rollout_pct", 0)}


@api.get("/platform/rollouts/usage-v2")
async def rollout_usage_v2():
    return {"status": "in_progress", "cohort_pct": 25, "errors_last_24h": 2,
            "checklist": [
                {"id": "c1", "label": "Dry run", "done": True},
                {"id": "c2", "label": "Cohort 10%", "done": True},
                {"id": "c3", "label": "Cohort 25%", "done": True},
                {"id": "c4", "label": "Cohort 50%", "done": False},
                {"id": "c5", "label": "Cut over", "done": False},
            ]}


@api.post("/platform/impersonate")
async def impersonate(body: Dict[str, Any]):
    return {"ok": True, "expires_at": (datetime.now(timezone.utc) + timedelta(minutes=30)).isoformat()}


# ─── STE simulate (SSE) ──────────────────────────────────────────────────
import asyncio
import json as _json
import math
from fastapi.responses import StreamingResponse


@api.post("/ste/simulate")
async def ste_simulate(body: Dict[str, Any]):
    """
    Stream Monte-Carlo simulation frames as Server-Sent Events.
    The cockpit's SimulationPanel.vue reads this with fetch + ReadableStream.
    """
    chain       = body.get("chain", "session_lifecycle")
    start_state = body.get("start_state", "visitor")
    runs        = int(body.get("runs", 1000))
    steps       = int(body.get("steps", 10))

    states = ["visitor", "trial", "active", "expansion", "champion", "churned"]
    if start_state not in states:
        states.insert(0, start_state)

    async def event_stream():
        # Frame 0 — meta
        yield f"data: {_json.dumps({'kind': 'meta', 'chain': chain, 'runs': runs, 'steps': steps, 'start_state': start_state, 'states': states})}\n\n"
        # Frames 1..N — progressive convergence on a target distribution
        target = {
            "visitor":   0.18,
            "trial":     0.14,
            "active":    0.31,
            "expansion": 0.12,
            "champion":  0.09,
            "churned":   0.16,
        }
        # Ensure states present in target
        for s in states:
            target.setdefault(s, 0.05)
        total = sum(target.values())
        for k in target:
            target[k] /= total

        frames = 24
        for i in range(1, frames + 1):
            t = i / frames
            # Eased convergence (cubic)
            ease = 1 - math.pow(1 - t, 3)
            dist = {s: round(((1 - ease) * (1.0 / len(states)) + ease * target[s]) * (1 + 0.04 * (random.random() - 0.5)), 4) for s in states}
            # Renormalize
            tot = sum(dist.values())
            dist = {k: round(v / tot, 4) for k, v in dist.items()}

            activation = round(dist.get("active", 0) + dist.get("expansion", 0) + dist.get("champion", 0), 4)
            churn      = round(dist.get("churned", 0), 4)
            ev         = round(activation - churn, 4)

            payload = {
                "kind": "frame",
                "frame": i,
                "total": frames,
                "progress": round(i / frames, 3),
                "distribution": dist,
                "activation_probability": activation,
                "churn_probability": churn,
                "expected_value": ev,
            }
            yield f"data: {_json.dumps(payload)}\n\n"
            await asyncio.sleep(0.12)

        # Final
        final_dist = dist
        activation = round(final_dist.get("active", 0) + final_dist.get("expansion", 0) + final_dist.get("champion", 0), 4)
        churn      = round(final_dist.get("churned", 0), 4)
        ev         = round(activation - churn, 4)
        ci_low     = max(0.0, round(activation - 0.04, 4))
        ci_high    = min(1.0, round(activation + 0.04, 4))
        yield f"data: {_json.dumps({'kind':'done','activation_probability':activation,'churn_probability':churn,'expected_value':ev,'confidence_95':[ci_low,ci_high],'end_state_distribution':final_dist})}\n\n"

    return StreamingResponse(event_stream(), media_type="text/event-stream", headers={
        "Cache-Control": "no-cache",
        "Connection": "keep-alive",
        "X-Accel-Buffering": "no",
    })


# ─── Admin copy state + experiments ──────────────────────────────────────
_COPY_STATE: Dict[str, str] = {
    "empty_state": "on", "banner": "on", "modal": "on",
    "tooltip": "on", "success_state": "on", "error_state": "on",
}


@api.get("/admin/copy/state")
async def admin_copy_state():
    return {"state": _COPY_STATE}


@api.put("/admin/copy/state")
async def admin_copy_state_set(body: Dict[str, Any]):
    surface = body.get("surface")
    value   = body.get("value", "on")
    if surface in _COPY_STATE:
        _COPY_STATE[surface] = value
    return {"state": _COPY_STATE}


@api.get("/platform/ste/matrix")
async def ste_matrix():
    rows = ["signup", "onboard", "first_flow", "first_auto_run", "paying", "churn_risk"]
    cols = ["t-0", "t-1", "t-7", "t-14", "t-30"]
    return {
        "rows": rows,
        "cols": cols,
        "cells": [
            [round(random.uniform(0.05, 0.95), 2) for _ in cols]
            for _ in rows
        ],
        "winning_tags": [
            {"tag": "invited:admin", "lift": 0.27},
            {"tag": "onboarded_day1", "lift": 0.19},
            {"tag": "first_flow_under_72h", "lift": 0.14},
        ],
        "dropoff": [
            {"stage": "signup→onboard",      "pct": 0.82},
            {"stage": "onboard→first_flow",  "pct": 0.64},
            {"stage": "first_flow→auto_run", "pct": 0.42},
            {"stage": "auto_run→paying",     "pct": 0.31},
        ],
    }


# ─── Onboarding ──────────────────────────────────────────────────────────
@api.post("/onboarding/complete")
async def onboarding_complete(body: Dict[str, Any]):
    return {"ok": True, "completed_at": now_iso()}


@api.post("/onboarding/step")
async def onboarding_step(body: Dict[str, Any]):
    return {"ok": True, "step": body.get("step"), "saved_at": now_iso()}


# ─── Billing ─────────────────────────────────────────────────────────────
@api.get("/billing")
async def billing():
    return {
        "plan": {"name": "Ops Pro", "monthly_usd": 249, "renews_at": past(-60 * 24 * 9)},
        "invoices": [
            {"id": "inv_007", "period": "2025-12", "amount": 249.00, "status": "paid",   "url": "#"},
            {"id": "inv_006", "period": "2025-11", "amount": 249.00, "status": "paid",   "url": "#"},
            {"id": "inv_005", "period": "2025-10", "amount": 249.00, "status": "paid",   "url": "#"},
            {"id": "inv_008", "period": "2026-01", "amount": 249.00, "status": "upcoming","url": "#"},
        ],
        "payment_method": {"brand": "Visa", "last4": "4242", "exp": "02/29"},
    }


# ─── Register router ─────────────────────────────────────────────────────
app.include_router(api)

# Enterprise endpoints (landing → register → cockpit flow)
try:
    from enterprise_api import router as enterprise_router, scim as scim_router
    app.include_router(enterprise_router)
    app.include_router(scim_router)
except Exception as _e:  # pragma: no cover
    import traceback
    print("[enterprise_api] failed to import:", _e)
    traceback.print_exc()


@app.get("/")
async def root():
    return {
        "service": "SpiderNetOS Cockpit — Mock API",
        "docs": "/docs",
        "health": "/api/health",
    }
