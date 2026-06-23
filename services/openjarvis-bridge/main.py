"""
OpenJarvis Bridge — SpiderNetOS adapter for OpenJarvis local-first AI.

Proxies to a running OpenJarvis server when OPENJARVIS_URL is set.
Otherwise provides SpiderNetOS-native agent presets, skills catalog,
and optional OpenAI fallback for chat augmentation.
"""

from __future__ import annotations

import glob
import os
import re
from datetime import datetime
from pathlib import Path
from typing import Any, Optional

import httpx
import yaml
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="SpiderNetOS OpenJarvis Bridge", version="1.0.0")

OPENJARVIS_URL = os.getenv("OPENJARVIS_URL", "").rstrip("/")
OPENJARVIS_API_KEY = os.getenv("OPENJARVIS_API_KEY", "")
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
INFERENCE_URL = os.getenv("INFERENCE_URL", "").rstrip("/")
SKILLS_ROOT = Path(os.getenv("JARVIS_SKILLS_ROOT", "/app/skills"))

# OpenJarvis built-in agents mapped for Atlas AIOS operators
AGENT_CATALOG = [
    {
        "id": "orchestrator",
        "display_name": "Orchestrator",
        "type": "on-demand",
        "description": "Multi-turn reasoning with automatic tool selection across SpiderNetOS agents.",
        "openjarvis_agent": "orchestrator",
        "spidernet_route": "meta_planner",
    },
    {
        "id": "deep_research",
        "display_name": "Deep Research",
        "type": "on-demand",
        "description": "Multi-hop research with citations across web and tenant docs.",
        "openjarvis_agent": "deep_research",
        "spidernet_route": "prism",
    },
    {
        "id": "native_react",
        "display_name": "ReAct Agent",
        "type": "on-demand",
        "description": "Thought-Action-Observation loop for complex operator tasks.",
        "openjarvis_agent": "native_react",
        "spidernet_route": "nexus",
    },
    {
        "id": "code_assistant",
        "display_name": "Code Assistant",
        "type": "on-demand",
        "description": "CodeAct-style assistance for flows, integrations, and automation scripts.",
        "openjarvis_agent": "native_openhands",
        "spidernet_route": "forge",
    },
    {
        "id": "monitor_operative",
        "display_name": "Monitor Operative",
        "type": "continuous",
        "description": "Long-horizon monitoring with memory and retrieval for AIOS tenants.",
        "openjarvis_agent": "monitor_operative",
        "spidernet_route": "sentinel",
    },
    {
        "id": "morning_digest",
        "display_name": "Morning Digest",
        "type": "scheduled",
        "description": "Daily briefing from calendar, comms, and platform metrics.",
        "openjarvis_agent": "morning_digest",
        "spidernet_route": "outcomes",
    },
    {
        "id": "simple",
        "display_name": "Simple Chat",
        "type": "on-demand",
        "description": "Lightweight single-turn chat without tools.",
        "openjarvis_agent": "simple",
        "spidernet_route": "atlas",
    },
]

AGENT_BY_ID = {a["id"]: a for a in AGENT_CATALOG}


class AskRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=8000)
    agent: str = Field(default="orchestrator")
    tenant_id: Optional[str] = None
    session_id: Optional[str] = None
    context: dict[str, Any] = Field(default_factory=dict)
    skills: list[str] = Field(default_factory=list)


class ResearchRequest(BaseModel):
    query: str = Field(..., min_length=1, max_length=4000)
    tenant_id: Optional[str] = None
    max_hops: int = Field(default=3, ge=1, le=8)


def _load_skills() -> list[dict[str, Any]]:
    entries: list[dict[str, Any]] = []
    if not SKILLS_ROOT.is_dir():
        return entries
    for path in sorted(glob.glob(str(SKILLS_ROOT / "*/SKILL.md"))):
        try:
            text = Path(path).read_text(encoding="utf-8")
            meta = _parse_skill_frontmatter(text)
            entries.append(
                {
                    "skill_id": meta.get("name") or Path(path).parent.name,
                    "display_name": meta.get("display_name") or Path(path).parent.name,
                    "description": meta.get("description", ""),
                    "category": meta.get("category", "general"),
                    "standard": "agentskills.io",
                    "installable": True,
                }
            )
        except OSError:
            continue
    return entries


def _parse_skill_frontmatter(text: str) -> dict[str, str]:
    match = re.match(r"^---\s*\n(.*?)\n---", text, re.DOTALL)
    if not match:
        return {}
    try:
        data = yaml.safe_load(match.group(1)) or {}
        return {str(k): str(v) for k, v in data.items() if v is not None}
    except yaml.YAMLError:
        return {}


async def _proxy_openjarvis_chat(message: str, agent: str) -> Optional[dict[str, Any]]:
    if not OPENJARVIS_URL:
        return None
    headers: dict[str, str] = {"Content-Type": "application/json"}
    if OPENJARVIS_API_KEY:
        headers["Authorization"] = f"Bearer {OPENJARVIS_API_KEY}"
    payload = {
        "model": agent,
        "messages": [{"role": "user", "content": message}],
        "stream": False,
    }
    try:
        async with httpx.AsyncClient(timeout=60.0) as client:
            resp = await client.post(
                f"{OPENJARVIS_URL}/v1/chat/completions",
                json=payload,
                headers=headers,
            )
            if resp.status_code >= 400:
                return None
            data = resp.json()
            content = (
                data.get("choices", [{}])[0]
                .get("message", {})
                .get("content", "")
            )
            usage = data.get("usage", {})
            total_tokens = int(usage.get("total_tokens", 0) or 0)
            return {
                "text": content,
                "source": "openjarvis",
                "model": data.get("model", agent),
                "usage": usage,
                "estimated_cost_usd": round(total_tokens * 0.0, 6),
            }
    except httpx.HTTPError:
        return None


async def _proxy_inference_chat(message: str, agent: str) -> Optional[dict[str, Any]]:
    """Fallback to SpiderNetOS inference plane when no OpenJarvis edge node."""
    if not INFERENCE_URL:
        return None
    system = (
        f"You are Atlas augmented by OpenJarvis ({agent}). "
        "Help AIOS operators with SpiderNetOS automations and outcomes."
    )
    try:
        async with httpx.AsyncClient(timeout=90.0) as client:
            resp = await client.post(
                f"{INFERENCE_URL}/generate",
                json={
                    "prompt": message,
                    "system_prompt": system,
                    "model": os.getenv("SPIDERNET_PROMPT_ENHANCER_MODEL", "gemma4"),
                    "temperature": 0.4,
                    "max_tokens": 1024,
                },
            )
            if resp.status_code >= 400:
                return None
            data = resp.json()
            tokens = int(data.get("tokens_used", 0) or 0)
            cost = float(data.get("cost", 0.0) or 0.0)
            return {
                "text": data.get("text", ""),
                "source": "inference_plane",
                "model": data.get("model", "gemma4"),
                "usage": {"total_tokens": tokens},
                "estimated_cost_usd": cost,
            }
    except httpx.HTTPError:
        return None


async def _openai_chat(message: str, system: str) -> Optional[dict[str, Any]]:
    if not OPENAI_API_KEY:
        return None
    try:
        async with httpx.AsyncClient(timeout=45.0) as client:
            resp = await client.post(
                "https://api.openai.com/v1/chat/completions",
                json={
                    "model": os.getenv("OPENJARVIS_FALLBACK_MODEL", "gpt-4o-mini"),
                    "messages": [
                        {"role": "system", "content": system},
                        {"role": "user", "content": message},
                    ],
                    "temperature": 0.4,
                },
                headers={
                    "Authorization": f"Bearer {OPENAI_API_KEY}",
                    "Content-Type": "application/json",
                },
            )
            if resp.status_code >= 400:
                return None
            data = resp.json()
            content = (
                data.get("choices", [{}])[0]
                .get("message", {})
                .get("content", "")
            )
            usage = data.get("usage", {})
            return {
                "text": content,
                "source": "openai_fallback",
                "model": data.get("model", "gpt-4o-mini"),
                "usage": usage,
                "estimated_cost_usd": round(
                    (usage.get("total_tokens", 0) or 0) * 0.00000015, 6
                ),
            }
    except httpx.HTTPError:
        return None


def _fallback_response(message: str, agent: str, skills: list[str]) -> dict[str, Any]:
    spec = AGENT_BY_ID.get(agent, AGENT_BY_ID["orchestrator"])
    skill_note = f" Skills invoked: {', '.join(skills)}." if skills else ""
    templates = {
        "deep_research": (
            f"Research plan for: {message}\n\n"
            "1. Gather tenant context from SpiderNetOS event log\n"
            "2. Cross-reference platform metrics and outcome recommendations\n"
            "3. Synthesize findings with citations from internal traces\n"
            "4. Propose actionable next steps for the operator"
        ),
        "code_assistant": (
            f"Code assistance scope: {message}\n\n"
            "Suggested approach:\n"
            "- Inspect existing flows via Forge agent\n"
            "- Validate DAG with dag-compiler\n"
            "- Run guarded execution through Runtime Guardian"
        ),
        "morning_digest": (
            "Daily AIOS briefing:\n"
            "- Pending outcome recommendations\n"
            "- Agent health across tenant workspace\n"
            "- Budget and spend vs. limits\n"
            "- Top automation opportunities"
        ),
        "monitor_operative": (
            f"Monitoring watch registered for: {message}\n"
            "Sentinel will track anomalies and surface alerts in the cockpit."
        ),
    }
    text = templates.get(
        agent,
        f"[{spec['display_name']}] {message}\n\n"
        f"Routing via SpiderNetOS Meta-Planner → {spec['spidernet_route']} agent."
        f"{skill_note}",
    )
    return {
        "text": text,
        "source": "bridge_fallback",
        "model": "local-template",
        "usage": {"total_tokens": 0},
        "estimated_cost_usd": 0.0,
    }


@app.get("/health")
@app.get("/api/health")
async def health():
    openjarvis_reachable = False
    inference_reachable = False
    if OPENJARVIS_URL:
        try:
            async with httpx.AsyncClient(timeout=3.0) as client:
                r = await client.get(f"{OPENJARVIS_URL}/health")
                openjarvis_reachable = r.status_code == 200
        except httpx.HTTPError:
            pass
    if INFERENCE_URL:
        try:
            async with httpx.AsyncClient(timeout=3.0) as client:
                r = await client.get(f"{INFERENCE_URL}/health")
                inference_reachable = r.status_code == 200
        except httpx.HTTPError:
            pass
    return {
        "status": "ok",
        "openjarvis_url": OPENJARVIS_URL or None,
        "openjarvis_reachable": openjarvis_reachable,
        "inference_url": INFERENCE_URL or None,
        "inference_reachable": inference_reachable,
        "skills_loaded": len(_load_skills()),
        "agents": len(AGENT_CATALOG),
        "timestamp": datetime.utcnow().isoformat() + "Z",
    }


@app.get("/v1/agents")
async def list_agents():
    return {"data": AGENT_CATALOG}


@app.get("/v1/skills")
async def list_skills():
    return {"data": _load_skills()}


@app.post("/v1/ask")
async def ask(body: AskRequest):
    agent = body.agent if body.agent in AGENT_BY_ID else "orchestrator"
    spec = AGENT_BY_ID[agent]

    result = await _proxy_openjarvis_chat(body.message, spec["openjarvis_agent"])
    if result is None:
        result = await _proxy_inference_chat(body.message, spec["openjarvis_agent"])
    if result is None:
        system = (
            f"You are Atlas augmented by OpenJarvis ({spec['display_name']}). "
            f"Help AIOS operators with SpiderNetOS. Route context to {spec['spidernet_route']} when executing."
        )
        result = await _openai_chat(body.message, system)
    if result is None:
        result = _fallback_response(body.message, agent, body.skills)

    return {
        "ok": True,
        "agent": agent,
        "spidernet_route": spec["spidernet_route"],
        "response": result,
        "intelligence_per_watt": {
            "local_first": result.get("source") in ("openjarvis", "bridge_fallback", "inference_plane"),
            "estimated_cost_usd": result.get("estimated_cost_usd", 0.0),
            "tokens_used": (result.get("usage") or {}).get("total_tokens", 0),
        },
    }


@app.post("/v1/research")
async def research(body: ResearchRequest):
    ask_body = AskRequest(
        message=body.query,
        agent="deep_research",
        tenant_id=body.tenant_id,
        context={"max_hops": body.max_hops},
    )
    result = await ask(ask_body)
    result["citations"] = []
    result["hops"] = body.max_hops
    return result


@app.get("/v1/info")
async def info():
    return {
        "name": "SpiderNetOS OpenJarvis Bridge",
        "openjarvis_project": "https://github.com/open-jarvis/OpenJarvis",
        "license": "Apache-2.0",
        "integration": "atlas-aios",
    }
