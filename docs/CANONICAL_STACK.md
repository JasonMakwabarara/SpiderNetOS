# SpiderNetOS Canonical Stack (v3)

**Use this stack for all development, demos, and production pilots.**

## Start

```powershell
docker compose -f docker-compose.unified.yml up -d --build
# or
powershell -ExecutionPolicy Bypass -File scripts/up.ps1
```

Open: http://localhost

### Customer-facing cockpit routes (pack-aware)

| Route | Purpose |
|-------|---------|
| `/cockpit/#/billing` | Platform subscription & AI usage |
| `/cockpit/#/financial` | Financial OS (business finance) |
| `/cockpit/#/sales` | Sales & CRM OS |
| `/cockpit/#/compliance` | Compliance Radar |
| `/cockpit/#/operate/first-win` | Guided first automation |
| `/cockpit/#/feature-packs` | Install vertical packs (personalized by industry + feedback) |

Pack catalogue is **tenant-personalized**: relevance scores, industry-specific outcomes, and recommendations grow from business profile, usage signals, and thumbs-up/down feedback.

### Atlas trust gate (`automation_level`)

Tenants store `automation_level` on the `tenants` table: `manual`, `assisted` (default), or `autonomous`. Atlas uses `AtlasClarityGate` after business-profile discovery:

| Level | Behavior |
|-------|----------|
| **manual** | Every actionable intent asks for confirmation until that intent type is confirmed 3 times (trust earned per automation). |
| **assisted** | Reversible actions run immediately; irreversible actions always confirm; low model confidence triggers clarify (only when inference plane is configured). |
| **autonomous** | Acts by default; irreversible actions still confirm; genuinely ambiguous prompts clarify. |

Chat metadata modes: `discover` (profile), `clarify` (focused question), `confirm` (preview + pending action), `act` (dispatched). Confirm via `POST /api/atlas/confirm` with `{ action_id, decision: proceed|cancel }`. Trust counts live in `tenant_business_profiles.learned_signals.trust`.

Update level: `PUT /api/admin/tenant/automation-level` with `{ "automation_level": "manual|assisted|autonomous" }`.

See also: [OpenJarvis × Atlas integration](OPENJARVIS_INTEGRATION.md) — local-first AI augmentation for AIOS operators.

After cockpit UI changes:

```powershell
cd cockpit; npm run build; cd ..
Copy-Item -Recurse -Force cockpit\dist frontend\public\cockpit
docker compose -f docker-compose.unified.yml up -d --build frontend
```

## Architecture

| Layer | Service | Port |
|-------|---------|------|
| Landing + cockpit shell | frontend (nginx) | 80 |
| Business API (source of truth) | Laravel `api` | 8000 |
| Enterprise auth / SCIM / AIOS | FastAPI `cockpit-api` | 8001 |
| V2 intelligence | semantic-gateway + workers | 8005 |
| Inference plane (Ollama/Gemma) | inference | 9000 |
| OpenJarvis bridge | openjarvis-bridge | 8010 |

### Inference plane

Local-first Atlas responses use the `inference` service (`INFERENCE_URL=http://inference:9000`):

1. Start stack: `docker compose -f docker-compose.unified.yml up -d --build`
2. Pull model: `docker compose exec ollama ollama pull gemma2:2b` (or set `SPIDERNET_PROMPT_ENHANCER_MODEL` to another Ollama tag)
3. Verify: `GET http://localhost:9000/health` and `POST http://localhost:9000/v1/classify`

OpenJarvis bridge (`:8010`) proxies to an external OpenJarvis edge node when `OPENJARVIS_URL` is set; otherwise it falls back to the inference plane, then OpenAI, then templates.

### Real flow execution

- `POST /api/flows/quick-create` then `POST /api/flows/{id}/execute` runs a real DAG via `DagExecutionService` + `NodeActionRunner`
- Completed executions appear in `GET /api/traces` with `status: completed`
- Scheduled flows use `schedule_cron` on `flows` and `DispatchScheduledFlowsJob` (scheduler container)

### Nginx + Docker DNS

The frontend nginx config uses Docker’s embedded resolver (`127.0.0.11`) with variable-based `proxy_pass` so upstream hostnames (especially `api`) are re-resolved after container restarts. Without this, `/api/*` on port 80 can silently proxy to the wrong container until nginx is reloaded.

## Identity (Improvement #1)

- **Laravel Sanctum** is the canonical identity plane for cockpit business APIs.
- Sign-in at `/sign-in` issues a unified session (`access_token`, `user`, `tenant`, `caps`).
- Enterprise FastAPI auth is used for SSO, SCIM, magic link, and AIOS bundles only.

## Repo consolidation (Improvement #3)

| Repo | Status |
|------|--------|
| `SpiderNetOS` (this monorepo, `landing` branch) | **Canonical** |
| `SpiderNetOS-V1` | Archive / reference only |
| `SpiderNetOS-V2` | Absorbed as `services/*` microservices |

## Verify

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-unified.ps1
```
