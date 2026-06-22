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
| `/cockpit/#/feature-packs` | Install vertical packs |

See also: [OpenJarvis × Atlas integration](OPENJARVIS_INTEGRATION.md) — local-first AI augmentation for AIOS operators.

## Architecture

| Layer | Service | Port |
|-------|---------|------|
| Landing + cockpit shell | frontend (nginx) | 80 |
| Business API (source of truth) | Laravel `api` | 8000 |
| Enterprise auth / SCIM / AIOS | FastAPI `cockpit-api` | 8001 |
| V2 intelligence | semantic-gateway + workers | 8005 |
| OpenJarvis bridge | openjarvis-bridge | 8010 |

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
