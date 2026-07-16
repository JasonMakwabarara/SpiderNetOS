# SpiderNetOS — Cockpit (Frontend)

Vue 3 + Vite operator console for **SpiderNetOS**, a multi-tenant AI-native
business operating system. This app is the **flight deck** — not a dashboard,
not a chat UI. It is the single pane of glass where operators review
automated decisions, approve sensitive changes, tune governance, and
observe traces across agents, flows, and intelligence workers.

Positioning: dark-first, electric cyan accent, calm hierarchy. No rainbow
gradients. Data density where useful, breathing room on marketing-like
onboarding screens.

---

## Stack

| Layer | Choice |
| --- | --- |
| Framework | Vue 3 (Composition API) |
| Build | Vite 5 |
| Router | vue-router 4 |
| State | Pinia |
| HTTP | Axios (central instance in `src/services/api.js`) |
| Realtime | laravel-echo + pusher-js (stubbed for mocks; TODO swap) |
| Styling | Tailwind CSS + DCT→flight-deck tokens in `src/style.css` |
| Tests | Vitest + @vue/test-utils + happy-dom |

---

## Run it

```bash
cd /app/cockpit
yarn install
yarn start          # vite on 0.0.0.0:3000
yarn build          # production bundle
yarn test           # Vitest — auth store + router guard
```

Supervisor is preconfigured to run `yarn start` in `/app/cockpit`.

### Env

Only one variable is required:

```bash
# /app/cockpit/.env
VITE_API_URL=https://aios-onboarding.preview.emergentagent.com
```

- `VITE_API_URL` — base URL for the API gateway. In this environment it
  points at the FastAPI mock backend in `/app/backend/server.py`. In
  production it should point to the real Laravel `/api` gateway.

---

## Backend contract

Until the Laravel API is wired in, a **FastAPI mock backend** lives at
`/app/backend/server.py`. Every endpoint the cockpit calls is implemented
there with realistic seeded data (agents, flows, approvals, traces,
usage/budget, intelligence workers, platform feature flags, STE matrix,
etc.).

Key endpoints (all prefixed `/api`):

| Endpoint | Purpose |
| --- | --- |
| `POST /auth/login`, `GET /auth/me`, `POST /auth/step-up` | Principal + MFA freshness |
| `POST /atlas/chat`, `POST /atlas/sessions`, `POST /atlas/execute` | Atlas conversational compiler |
| `GET /agents`, `GET /agents/templates`, `POST /agents` | Agents CRUD |
| `GET /flows`, `GET /flows/:id`, `POST /flows/:id/publish` | Flows + DAG detail |
| `GET /approvals`, `POST /approvals/:id/approve\|reject` | Approvals queue |
| `GET /traces`, `GET /traces/:id` | Trace timeline + drawer |
| `GET /usage/budget`, `GET /usage/current`, `GET /usage/daily` | Cost governance |
| `GET /platform/overview`, `GET /platform/feature-flags`, `GET /platform/ste/matrix` | Platform surfaces |

**TODO**: when the Laravel API is available, delete `server.py` and point
`VITE_API_URL` at the Laravel origin. All Pinia stores already
match Laravel's `{data: [...]}` envelope convention.

---

## Mock-to-Laravel mapping

| Cockpit store | Cockpit calls | Laravel route (target) |
| --- | --- | --- |
| `auth` | `POST /api/auth/login` | `AuthController@login` (Sanctum) |
| `auth` | `GET /api/auth/me` | `AuthController@me` |
| `atlas` | `POST /api/atlas/chat` | `AtlasController@chat` (throttled) |
| `agents` | `GET/POST/PUT/DELETE /api/agents(/:id)` | `AgentController` |
| `flows` | `GET/POST/PUT/DELETE /api/flows(/:id)` + `/execute` `/publish` | `FlowController` |
| `approvals` | `GET /api/approvals`, `POST /api/approvals/:id/approve` | `ApprovalController` |
| `traces` | `GET /api/traces` | `ObservabilityController@traces` |
| `usage` | `GET /api/usage/budget`, `/current`, `/daily` | `UsageController` |

The `src/composables/useWebSocket.js` composable has a `TODO (Laravel)`
block — re-instate the Echo + Pusher wiring once the broadcaster is up.

---

## Information architecture

```
/login, /403, /404                         public
/                                          Dashboard
/atlas                                     Command + context surface
/agents, /agents/new                       Agent list + create
/agents/builder(/:templateId)              Agent builder wizard
/flows, /flows/new, /flows/:id             Flow list + DAG builder
/approvals                                 Split-pane approvals queue
/traces                                    Searchable trace timeline
/intelligence                              Intelligence workers
/memory                                    Knowledge / memory objects
/usage                                     Cost & budgets
/settings, /settings/usage                 Tenant settings
/settings/automation-level                 Automation mode editor (cap-gated)
/billing                                   Plans + invoices
/onboarding                                First-run wizard (gates app)

/admin                                     Admin dashboard (role: admin|super_admin)
/admin/users, /admin/audit, /admin/copy, /admin/budget

/platform                                  Platform workspace (role: super_admin)
/platform/feature-flags                    Step-up gated
/platform/rollouts/usage-v2                Step-up gated
/platform/ste                              State Transition Engine read-only
```

Role-aware left nav is grouped **Operate / Build / Observe / Tenant**,
and the top bar exposes a workspace pivot (User ↔ Admin ↔ Platform)
visible only to principals with the matching role.

---

## Visual system

Tokens live in `src/style.css` and mirror into Tailwind via
`tailwind.config.js`. The token vocabulary:

| Token | Purpose |
| --- | --- |
| `--bg`, `--bg-subtle`, `--bg-card`, `--bg-elevated` | Surface ramp |
| `--text-primary`, `--text-secondary`, `--text-muted` | Text ramp |
| `--accent` `#00E5C8`, `--accent-weak` | Cyan — **the only brand color** |
| `--amber` `#F5A524` | Warnings, budget alerts |
| `--success` `#22D39B`, `--danger` `#FF5A7A` | Status colors |
| `--border`, `--divider`, `--border-active` | Lines |
| `--glass-panel`, `--glass-card` | Translucent surfaces (top bar, modals) |

Classes: `.sn-card`, `.sn-panel`, `.sn-btn`, `.sn-btn-primary`,
`.sn-btn-danger`, `.sn-pill(-accent|-warn|-danger|-success)`,
`.sn-nav-link`, `.sn-kbd`, `.sn-grad-text`. Legacy `.dct-*` classes
alias to these so older templates still theme correctly.

---

## Demo / testing

- **Login**: any email + password is accepted. Pick a role
  (`user` / `admin` / `super_admin`) from the role picker on the
  login card. Default is `super_admin` so the full IA is visible.
- **Role switcher**: available in the top-bar user menu — swap role
  on the fly without re-authenticating.
- **Realtime**: stubbed as always-connected 420ms after login. Replace
  with real Echo wiring to restore live trace / approval push.

---

## Files worth opening first

- `src/App.vue` — the shell (top bar, sidebar, breadcrumbs, command palette trigger).
- `src/router/index.js` — all routes, guards (guest / auth / roles / capability / stepUp).
- `src/stores/auth.js` — principal, capabilities, role switcher, step-up.
- `src/views/Login.vue`, `src/views/Dashboard.vue`, `src/views/Atlas.vue` — headline surfaces.
- `src/style.css` + `tailwind.config.js` — design tokens.
- `tests/auth.store.test.js`, `tests/router.guard.test.js` — Vitest examples.
