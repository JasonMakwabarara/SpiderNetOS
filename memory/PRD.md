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

- **Cockpit** `/app/cockpit/` — Vue 3 + Vite + Pinia + Tailwind. Supervisor runs `yarn start` on :3000.
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

## Implemented — sessions 2026-01

- **Infra**
  - Cockpit app lives at `/app/cockpit/` (supervisor expected path).
  - `package.json` rebuilt: `yarn start` runs Vite on :3000; added Vitest, happy-dom, @vue/test-utils.
  - `vite.config.js` locked to port 3000, allowedHosts=true, HMR via wss.
  - `.env` → `VITE_API_URL=https://spidernet-cockpit.preview.emergentagent.com`. `.env.example` documents optional Pusher / WS vars.
- **Design system (flight-deck)**
  - `tailwind.config.js` — cyan palette, legacy color remaps so legacy views inherit the theme.
  - `src/style.css` — token vocabulary + components (`sn-card`, `sn-btn`, `sn-pill`, `sn-nav-link`, `sn-kbd`, `sn-grad-text`).
- **Shell** (`src/App.vue`)
  - Top bar: env badge, breadcrumbs, WS status, ⌘K trigger, budget pill, role switcher.
  - Sidebar grouped Operate / Build / Observe / Tenant + Admin/Platform pivot.
  - Public-route bypass added so `/share/trace/:token` renders without the cockpit chrome.
- **Auth** — fake login via FastAPI mock; super_admin default; role swap from user menu; capabilities derived from a static map.
- **Dashboard** — automation mode, pending approvals, active agents, today's spend, Live Ops Feed, budget snapshot, Hannah suggestions, quick jumps.
- **Approvals** — split queue|diff, risk pills, low/medium → soft `ConfirmDialog`, high → `TypedConfirmDialog` (`Type I UNDERSTAND` + required reason).
- **Command palette** (`src/components/CommandBar.vue`)
  - Subsequence-fuzzy index over routes (role-aware) + actions.
  - **Live lanes** — fetches recent traces and pending approvals on open and surfaces them as dedicated empty-state groups.
  - Global hotkeys ⌘K / Ctrl+K / `/`.
  - **Cheat sheet** modal — opens with `?` (or footer button), grouped Navigation / Palette / Atlas slash commands.
- **Usage** — SVG cost sparkline (hover crosshair), DOW × week heatmap, anomalies feed, budget caps editor (PUT `/api/usage/budget`).
- **WebSocket** (`src/composables/useWebSocket.js`) — real Echo + Pusher restored with channel.listen for tenant-private events; auto-degrades to deterministic stub when `VITE_PUSHER_KEY` is unset.
- **STE Simulation Panel** (`src/components/ste/SimulationPanel.vue`) — completely rewritten. Calls `POST /api/ste/simulate` and consumes the **Server-Sent Events** stream via `fetch + ReadableStream`. UI animates KPIs, progress bar, and live distribution bars frame-by-frame.
- **Admin Copy Lab** — full retheme + **uplift histogram** (SVG). Bars in cyan for above-control, red for below, accent-strong for the winning arm; companion arm-rows table; methodology side panel with total impressions + best uplift.
- **Share-a-Trace** — `POST /api/traces/:id/share` mints a tenant-scoped, 7-day token. Cockpit shows a Share button on each expanded trace, opens a dialog with copy-to-clipboard URL. New public route `/share/trace/:token` (`SharedTrace.vue`, marked `meta.public`) bypasses every guard and reads `GET /api/public/traces/:token`.
- **All other views** (Atlas, Flows, FlowBuilder, Agents, Memory, Settings, Automation Level, Billing, Onboarding, Admin Dashboard, Admin Users, Admin Audit, Admin Budget, Platform Overview, Platform Feature Flags, Platform Rollouts, Platform STE, Forbidden, NotFound) — flight-deck themed.
- **FastAPI mock** (`/app/backend/server.py`) — every `/api/*` endpoint with `{data: ...}` envelope. New this round: `POST /api/traces/:id/share`, `GET /api/public/traces/:token`, `POST /api/ste/simulate` (StreamingResponse / SSE), `GET|PUT /api/admin/copy/state`.
- **Tests** — 11 Vitest cases passing (auth store + router guard).
- **Build** — `yarn build` produces a clean production bundle (~365 KB / 116 KB gzip).
- **Docs** — `/app/cockpit/README.md`, `/app/cockpit/.env.example`.

## Prioritized backlog

### P0 — none open
### P1 — infra-bound (cannot finish in this pod, but code paths are ready)
- Provision Laravel `/api` and flip `VITE_API_URL`. Then `rm /app/backend/server.py`. **MOCKED** until then.
- Provision a Pusher / Soketi broadcaster and supply `VITE_PUSHER_KEY`, `VITE_WS_HOST` etc — `useWebSocket.js` is already wired for it.
### P2 — future
- Track recent palette selections and surface a "Recently used" lane.
- Stream STE simulation results into a stacked-area chart (currently bars + KPIs).
- Histogram statistical-significance overlay on the AdminCopy uplift chart.
- Add a `/share/approval/:token` mirror of Share-a-Trace.
- Optional dark/light theme toggle (currently dark-only by design).

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
