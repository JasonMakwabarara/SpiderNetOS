# SpiderNetOS — Cockpit PRD

## Original problem statement

Design and implement the production frontend for **SpiderNetOS**: a multi-tenant,
AI-native **business operating system**. The frontend is the **Cockpit** — a
serious, trustworthy operator console for tenants, admins, and platform super-
admins. Primary metaphor: control room / flight deck for an autonomous-but-
governed system.

Core concepts surfaced in navigation and empty states: **Atlas**, **Agents**,
**Flows**, **Approvals**, **Traces**, **Intelligence workers**, **Memory**,
**Usage / cost governance**, **Settings** (incl. automation level / control
mode), **Billing**.

## User choices (captured at session start)

- Accent: **electric cyan**
- Mock scope: **rich** (FastAPI mock backend if available; else in-memory mocks)
- Auth: **fake login** (any credentials accepted by mock backend)
- Default role: **super_admin** + role switcher in user menu
- Tests: **included** (Vitest)

## Personas

| Persona | Cares about |
| --- | --- |
| Tenant user (operator) | Atlas chat, approvals to resolve, running flows, spend to date |
| Admin | Users, budget, audit, copy lab |
| Super admin (platform) | Feature flags, rollouts, State Transition Engine, impersonation |

## Architecture

- **Frontend** `/app/frontend/` — Vue 3 + Vite + Pinia + Tailwind. Supervisor runs `yarn start` on :3000.
- **Backend (mock)** `/app/backend/server.py` — FastAPI, mocked `/api/*` endpoints. Supervisor runs uvicorn on :8001.
- **Kubernetes ingress** routes `/api/*` to port 8001 and everything else to port 3000, preserving the preview URL.
- **Realtime (WS)** is stubbed (always-connected after 420ms) — see `src/composables/useWebSocket.js` `TODO (Laravel)` block for wiring Echo + Pusher when the broadcaster is up.

## Core requirements (static)

1. Dark-first UI, restrained single-accent (cyan `#00E5C8`), no rainbow gradients.
2. Full IA: all 18+ routes listed in the brief present and gated.
3. Route guards: `guest`, `requiresAuth`, `roles[]`, `capability`, onboarding.
4. Pinia stores mirror backend concepts; all existing store contracts (Laravel `{data: ...}` envelope) honored by the mock.
5. Cost governance visible globally (budget pill in top bar, snapshot on dashboard, full view on `/usage`).
6. Accessibility: keyboard focus ring (cyan), semantic landmarks, contrast.
7. Vitest tests for auth store + router guard decisions.

## Implemented — 2026-01 session

- **Infra**
  - Moved existing `/app/cockpit/` skeleton → `/app/frontend/` (supervisor expected path).
  - `package.json` rebuilt: `yarn start` runs Vite on :3000; added Vitest, happy-dom, @vue/test-utils.
  - `vite.config.js` locked to port 3000, allowedHosts=true, HMR via wss.
  - `.env` → `VITE_API_URL=https://spidernet-cockpit.preview.emergentagent.com`.
- **Design system (flight-deck)**
  - `tailwind.config.js` — cyan palette, legacy color remaps (indigo→cyan, red→danger, green→success, yellow→amber, gray→dark surfaces) so legacy views inherit the theme.
  - `src/style.css` — token vocabulary (`--bg`, `--accent`, `--text-*`), `.sn-card`, `.sn-btn(-primary|-danger)`, `.sn-pill(-accent|-warn|-danger|-success)`, `.sn-nav-link`, `.sn-kbd`, grid background, shimmer.
- **Shell** (`src/App.vue`)
  - Top bar: logo + tenant + env badge + breadcrumbs + WS status + command-palette trigger (⌘K) + budget pill + help + user menu with demo role switcher.
  - Sidebar: grouped Operate / Build / Observe / Tenant. Admin + Platform pivot.
  - Route transitions (opacity + subtle Y-translate).
- **Auth** (`src/stores/auth.js` + `src/views/Login.vue`)
  - Fake login against the FastAPI mock; role picked on the login card + swappable from the user menu.
  - Capabilities derived from role map; super_admin short-circuits `has()`.
  - localStorage persistence for token/user/tenant/caps/impersonation.
- **Dashboard** (`src/views/Dashboard.vue`) — rewritten: automation mode, pending approvals, active agents, today's spend, Live Ops Feed, budget snapshot, Hannah suggestions, quick jumps.
- **All existing views** (Atlas, Flows, FlowBuilder, Approvals, Traces, Agents, Intelligence, Memory, Usage, Settings, Automation Level, Billing, Onboarding, Admin Dashboard, Admin Users, Admin Audit, Admin Copy, Admin Budget, Platform Overview, Platform Feature Flags, Platform Rollouts, Platform STE, Forbidden, NotFound) — verified rendering in the flight-deck palette via live screenshots on the preview URL.
- **FastAPI mock** (`/app/backend/server.py`)
  - Health, Auth (login/register/me/logout/step-up), Atlas (chat/sessions/execute/cancel/events/enhance-prompt), Command, Agents (+ templates + delegation), Flows (+ execute/publish/executions), Approvals (+ approve/reject), Traces, Intelligence workers, Memory, Usage (budget/current/daily/monthly/series/anomalies), Admin (users/audit/copy), Platform (overview/feature-flags/rollouts/ste/impersonate), Onboarding, Billing.
  - All list endpoints return Laravel-style `{data: [...]}` envelope to match existing Pinia store contracts.
- **Tests** (`tests/`) — 11 passing (auth store: 5, router guard: 6).
- **Build** — `yarn build` produces a clean production bundle (dist/index-*.js ~344 KB, gzip 108 KB).
- **Docs** — `/app/frontend/README.md` with stack, run instructions, env, IA table, token legend, mock-to-Laravel map.

## Prioritized backlog

### P0 — none open
### P1 — polish
- Replace the stubbed WebSocket indicator with real Echo + Pusher wiring.
- Add a real command palette fuzzy search index (currently a `⌘K` trigger button only).
- Replace Approvals Approve/Reject plain buttons with a typed-confirm dialog for high-risk entries.
### P2 — future
- Token/cost series charts on `/usage` — replace placeholder list with a proper sparkline + heatmap.
- `/admin/copy` experiment uplift chart (histogram).
- `/platform/ste` simulation panel — wire to a real POST and stream results.
- Keyboard shortcut cheatsheet behind `?`.
- Dark/light theme toggle (currently dark-only by design).

## Next tasks on user resume

1. Wire real Laravel `/api` by setting `VITE_API_URL` — mock backend becomes a no-op.
2. Re-enable the `useWebSocket` composable with Echo + Pusher when broadcaster credentials land.
3. Implement typed-confirm dialog on destructive approvals + feature-flag toggle behind step-up.

## Enhancement recommendation

**Share-a-Trace**: give every completed trace a public read-only URL (tenant-
hashed) so operators can paste a trace into a Slack or a support ticket with a
single click. It's the highest-leverage "shareability" feature for a system
whose users spend 5 minutes/week — it turns each trace into a social object
and pulls non-operator stakeholders into the loop when something interesting
happens.
