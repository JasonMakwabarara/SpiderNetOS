# SpiderNetOS — Unified Product PRD

## Positioning
- **Headline:** "The AI Operating System for business automation."
- **Tagline:** Connect people, data, applications, and AI agents through one operating layer. Deploy AIOS components into any environment — startups, teams, or enterprises.
- **Primary CTA:** "Get started free"

## Unified architecture (12 May 2026)

```
┌─────────── React frontend (port 3000) ───────────┐
│  /                    → Landing                  │
│  /trust               → Public Trust Center      │
│  /sign-in             → 4-method auth            │
│  /enterprise/register → 10-step wizard           │
│  /cockpit/*           → STATIC mount → Vue       │
└───────────────────────────────────────────────────┘
        ↓ shared JWT in localStorage (dual keys)
┌────────── Vue Cockpit (built, served as static) ──┐
│  /cockpit/#/                → Dashboard           │
│  /cockpit/#/atlas           → Atlas (NL compiler) │
│  /cockpit/#/agents · /flows · /approvals · /traces│
│  /cockpit/#/intelligence · /memory · /usage       │
│  /cockpit/#/financial/{dashboard,ledger,invoices, │
│                       payments,portfolios}        │
│  /cockpit/#/admin/{users,audit,copy,budget}       │
│  /cockpit/#/platform/{flags,rollouts,ste}         │
│  /cockpit/#/enterprise/{connectors,aios,trust}    │  ← NEW
│  /cockpit/#/settings · /billing · /onboarding     │
└───────────────────────────────────────────────────┘
        ↓
┌─────────── FastAPI backend (port 8001) ──────────┐
│  /api/*            (legacy cockpit endpoints)    │
│  /api/enterprise/* (registration, AIOS, trust,    │
│                     SCIM, RBAC, audit)           │
│  /api/scim/v2/*    (SCIM 2.0)                     │
└───────────────────────────────────────────────────┘
```

## Why this architecture
- The Vue cockpit is the **real product** (32 views, 9 Pinia stores, full business automation: Atlas NL compiler, agents, flows, approvals, traces, intelligence, financial ledger, admin, platform). My earlier React cockpit stubs were redundant and have been deleted.
- The new React shell handles only what's *outside* the product: landing/marketing, trust, onboarding, sign-in.
- Single sign-in surface across both SPAs via dual-key localStorage bridge.
- Single backend (FastAPI) serves both — Vue's existing /api/* mocks and the new /api/enterprise/* enterprise endpoints share one tenant/user/audit model.

## Integration changes made
### Vue cockpit (`/app/cockpit/`)
1. **Hash router** + `base: '/cockpit/'` — works on any static host, no server SPA fallback needed.
2. **Dual-key auth store** — reads from `token`/`user`/`tenant` OR `sn_access_token`/`sn_user`/`sn_tenant`; persist & logout write both.
3. **Design tokens retuned** to the unified palette (`--bg: #070A12; --accent: #00D6C9; --accent-warm: #FF6B2C`) — surfaces inherit automatically.
4. **Top-bar logo** swapped to the SpiderNet node-graph mark used on landing.
5. **New "Enterprise" nav group** between Observe and Tenant with three new views:
   - `EnterpriseConnectors.vue` → connector catalog (ERP/CRM/IAM/Data) bound to `GET /api/enterprise/connectors`.
   - `EnterpriseAiosDownloads.vue` → signed AIOS bundle generation + verification + per-OS installer guide; uses `POST /api/enterprise/aios/bundle/create` and `GET /api/enterprise/aios/bundles`.
   - `EnterpriseTrust.vue` → live audit stream + uptime/SLA/audit/bundles tiles + compliance posture + on-demand signed export sample.

### React frontend (`/app/frontend/`)
1. **Deleted** `src/cockpit/*` (10 stub files) and the React Router `/cockpit/*` block.
2. New tiny `CockpitRedirect` gate that hard-redirects `/cockpit/*` to the static Vue index, preserving the hash.
3. `lib/api.js` `auth.saveSession` now **dual-writes** both key namespaces (and `caps`); `auth.clear` clears both.
4. `SignInPage` + `RegisterWizard` post-login `nav('/cockpit')` → `window.location.assign('/cockpit/')` (hard nav into Vue SPA).

### Backend (`/app/backend/enterprise_api.py`)
- `_issue_session` now returns `caps` (capability list) so Vue's RBAC hydrates correctly.

### Build pipeline
- `cd /app/cockpit && yarn build` → emits to `/app/cockpit/dist/` with `base: '/cockpit/'` so all asset URLs are absolute.
- `cp -r dist /app/frontend/public/cockpit/` mounts the Vue SPA inside CRA's public folder.
- Convenience: `yarn cockpit:build` in `/app/frontend/` re-runs the whole chain.

## What stays in the original Vue cockpit (untouched)
- 32 views including all Atlas / Agents / AgentBuilder / Flows / FlowBuilder / Approvals / Traces / Intelligence / Memory / Usage / Financial(×5) / Admin(×4) / Platform(×4) / Settings / Billing / Onboarding / Login.
- 9 Pinia stores.
- CommandBar, ImpersonationBanner, RoleBadge, capability gates, step-up auth gates.
- Login.vue stays as the cockpit's own fallback (e.g. when /cockpit/ is opened without a session).
- Onboarding.vue stays as the in-product tour (distinct from the public 10-step registration wizard).

## Backlog (carried + new)
- **P1 carried**: real OIDC/SAML via Authlib / python3-saml; real WebAuthn via fido2; email provider for magic links.
- **P1 carried**: httpOnly + SameSite cookie session migration.
- **P1 new**: Nginx production routing — `/cockpit/` → Vue dist directly (out of CRA `public/`), `/api/*` → FastAPI, everything else → React build.
- **P2 carried**: AIOS bundle payload → object storage; KMS-backed signing.
- **P2 carried**: SCIM `/Groups` + PATCH for full Okta/Entra inbound.
- **P2 new**: Replace deterministic Trust Center uptime series with real status pings.
- **P3**: i18n, WCAG AAA pass on security-critical copy, TS migration starting from `lib/api.js`.

## Files of note
- React: `src/App.js`, `src/pages/{LandingPage,SignInPage,RegisterWizard,TrustCenter}.jsx`, `src/lib/api.js`, `public/cockpit/` (built Vue bundle)
- Vue: `src/App.vue`, `src/router/index.js`, `src/stores/auth.js`, `src/style.css`, `src/views/enterprise/{EnterpriseConnectors,EnterpriseAiosDownloads,EnterpriseTrust}.vue`, `vite.config.js`
- Backend: `backend/server.py`, `backend/enterprise_api.py`
- Memory: `memory/PRD.md`, `memory/test_credentials.md`
