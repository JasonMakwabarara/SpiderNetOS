# SpiderNetOS — Test credentials & demo paths

Updated: 17 Jun 2026. Aligned with the unified v3 stack (`docker-compose.unified.yml`).

## Primary admin (Laravel + enterprise)

| Field | Value |
|---|---|
| Email | `admin@spidernetos.com` |
| Password | `Zukaarimoto01!` |
| Role | `super_admin` |
| Tenant ID | `00000000-0000-0000-0000-000000000001` |

## Sign-in flow (landing UI)

Path: **`http://localhost/sign-in`** (not `/cockpit/#/login`)

1. Choose **Email & password**
2. Sign-in establishes **two sessions**:
   - **Enterprise** — `POST /api/enterprise/auth/password/login` → FastAPI (Mongo)
   - **Laravel** — `POST /api/auth/login` → Sanctum token for cockpit business APIs
3. Redirect → **`http://localhost/cockpit/`**

Tokens are mirrored in localStorage: `sn_access_token` / `token`, `sn_user` / `user`, etc.

## API routing (nginx :80)

| Path | Backend |
|---|---|
| `/api/enterprise/*`, `/api/scim/*` | FastAPI cockpit-api :8001 |
| `/api/v2/intelligence/*` | Laravel :8000 (proxies to semantic-gateway) |
| `/api/v2/*` (other) | semantic-gateway :8005 |
| `/api/*` (agents, flows, atlas, auth, platform) | Laravel :8000 |

## Intelligence evaluate (Laravel proxy)

Requires Sanctum bearer token. `event_payload` must be a **JSON object** (array), not a bare string.

```http
POST /api/v2/intelligence/evaluate
Authorization: Bearer <laravel_token>
Content-Type: application/json

{
  "event_payload": { "type": "verify", "source": "manual" },
  "workspace_id": "00000000-0000-0000-0000-000000000001"
}
```

`tenant_id` is accepted as an alias for `workspace_id`.

MetaPlanner calls the same gateway on every `agent.dispatched` event (non-blocking on failure).

## Command dispatch

```http
POST /api/command
Authorization: Bearer <laravel_token>

{ "command": "Analyze pipeline bottlenecks for this week" }
```

Routes through MetaPlanner → Atlas agent → V2 evaluate → Redis `agent:dispatch`.

## Other sign-in flows (demo)

### Enterprise SSO — Demo IdP
- Tenant slug: `demo`
- Provider: **Demo IdP (OIDC, simulated)**
- `POST /api/enterprise/auth/sso/start` → `{ completed: true, access_token, ... }`

### Magic link
- Any email → `POST /api/enterprise/auth/magic-link/request`
- Dev mode returns `dev_link.token` in the response

### TOTP
- Code: `000000` (demo bypass)

### WebAuthn
- Demo stub via `POST /api/enterprise/auth/webauthn/login`

## Enterprise registration wizard

Path: **`http://localhost/enterprise/register`** — 10 steps.

## Local infrastructure

| Service | URL |
|---|---|
| Landing + cockpit shell | http://localhost |
| Laravel API (direct) | http://localhost:8000/api |
| Enterprise API (direct) | http://localhost:8001/api |
| V2 semantic gateway (direct) | http://localhost:8005 |
| OpenJarvis bridge (direct) | http://localhost:8010 |
| Postgres | `postgresql://postgres:postgres@localhost:5432/spidernet` |
| Mongo (enterprise) | `mongodb://localhost:27017/spidernetos` |
| Redis | `localhost:6379` |

## Verify the stack

```powershell
powershell -ExecutionPolicy Bypass -File scripts\verify-unified.ps1
```

## What's mocked vs real

| Flow | Status |
|---|---|
| Laravel agents / flows / command / platform | **Real** (Postgres + event_log) |
| Enterprise password login | **Real** (FastAPI + Mongo) |
| V2 intelligence evaluate | **Real** (semantic-gateway + pgvector schema) |
| OIDC/SAML real IdP exchange | Demo path only |
| WebAuthn ceremony | Demo stub |
| Magic-link email delivery | Token returned in API (`dev_link`) |
| AIOS bundle signing | Real Ed25519 + SHA-256 (in-process key) |
