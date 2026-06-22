# Unified SpiderNetOS — Quick Start (landing branch)

Single application: **React landing** → **Vue cockpit** → **FastAPI enterprise API** + **Laravel V1** + **V2 intelligence layer**.

## Architecture

```
http://localhost:3000   React landing (dev)
  /                     Landing page
  /sign-in              Enterprise auth (SSO, magic link, passkey, TOTP)
  /enterprise/register  10-step onboarding wizard
  /cockpit/             Vue cockpit (static bundle)

http://localhost:8001   FastAPI cockpit-api
  /api/*                Cockpit mocks
  /api/enterprise/*     Registration, AIOS bundles, trust, SCIM

http://localhost:8000   Laravel api (V1 business logic)
  /api/agents, /api/flows, /api/atlas, …

http://localhost:8005   V2 semantic gateway
  /api/v2/*             Perception, DAG compiler, RL, guardian
```

## Dev (recommended)

### 1. Start backend services

```powershell
cd SpiderNetOS
docker compose -f docker-compose.unified.yml up -d --build mongo postgres redis cockpit-api semantic-gateway atlas-perception dag-compiler atlas-rl runtime-guardian
```

### 2. Start landing frontend

```powershell
cd frontend
npm install
npm start
```

Open **http://localhost:3000**

### 3. Sign in → Cockpit

Use **Enterprise SSO** with tenant `demo` → redirects to `/cockpit/` with shared JWT in localStorage.

## Production (Docker)

```powershell
cd cockpit && npm run build
cd ../frontend && npm run cockpit:build
docker compose -f docker-compose.unified.yml up -d --build
```

Open **http://localhost**

## Laravel ↔ V2 bridge

Laravel proxies intelligence calls server-side:

- `GET  /api/v2/intelligence/health`
- `POST /api/v2/intelligence/evaluate`
- `POST /api/v2/intelligence/atlas/coordinate-cycle`
- `POST /api/v2/intelligence/compile`

Configure via `INTELLIGENCE_GATEWAY_URL=http://semantic-gateway:8000`.
