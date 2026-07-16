# SpiderNetOS Roadmap

**Version:** 3.2 → 4.0  
**Last Updated:** 2026-05-02  

## Thesis

SpiderNetOS is not a CRM, a workflow tool, or an AI assistant. It is a **self-optimizing business operating system** that an owner buys to improve their business without constant input. The owner spends 5–10 minutes per week reviewing outcomes; the system runs hundreds of decisions per day, continuously learning what works and converging toward better states.

---

## 90-day cycle cadence

This roadmap runs on Priestley's Five A's (`docs/internal/operating-model.md`): each phase below is worked in ~90-day cycles. At the start of a cycle: reconnect with the thesis above, review `docs/internal/awareness-list.md`, and set the cycle's targets in `docs/internal/3-1-90.md`. At the end: update the asset register (`docs/internal/asset-register.md`) with what now runs without further effort, and roll anything unfinished into the next cycle *deliberately* rather than silently.

---

## Phase Map

### Phase 0 — Foundations (Shipped: v3.2)

**Core runtime in production:**

- Event-sourced architecture (`event_log` as source of truth)
- State Transition Engine Phase 1 (read-only projections)
- Six core agents: Atlas, Hannah, Forge, Sentinel, Prism, Nexus
- MetaPlanner with Hard Rules (#1–#4)
- CostGovernor for budget enforcement
- Cockpit Vue SPA with real-time Soketi
- Thompson Sampling bandit for Atlas copy optimization

**Deliverables:**
- `backend/` — Laravel API with STE projections
- `intelligence/` — Python workers with 6 core agents
- `cockpit/` — Vue SPA with Platform State Engine dashboard
- `tests/client/` — HTTP contract test harness

---

### Phase 1 — Initial Conditions & Control Modes ✅ (core shipped — monitor STE projections in prod)

**Goal:** Capture the initial-conditions vector for every tenant and expose the Manual/Assisted/Autonomous control primitive.

#### Phase 1 checklist → implementation traces

| Criterion | Status | Where it lives |
|-----------|--------|----------------|
| Admin completes onboarding wizard → lands on **`/atlas?seed=onboarding`** | ✅ | [`cockpit/src/views/Onboarding.vue`](../cockpit/src/views/Onboarding.vue) (`router.push('/atlas?seed=onboarding')` after `/api/admin/onboarding/complete`). |
| Onboarding persisted per step + observability honour step | ✅ | [`cockpit/src/composables/useOnboarding.js`](../cockpit/src/composables/useOnboarding.js) · Laravel [`OnboardingController`](../backend/app/Http/Controllers/Admin/OnboardingController.php). |
| **`tenant.onboarding_active → active`** STE edges | ✅ | [`backend/database/seeders/SteEventMappingSeeder.php`](../backend/database/seeders/SteEventMappingSeeder.php) rows for `tenant.onboarding.started` / `completed` / `activated`. |
| **Migration** adds `automation_level` + onboarding JSON | ✅ | [`backend/database/migrations/2026_04_21_000001_add_onboarding_to_tenants_and_users.php`](../backend/database/migrations/2026_04_21_000001_add_onboarding_to_tenants_and_users.php). |
| Router gate forces `/onboarding` until complete | ✅ | [`cockpit/src/router/index.js`](../cockpit/src/router/index.js). |
| `automation_level` injected before dispatch + present on **`agent.dispatched`** payload & metadata | ✅ | [`backend/app/Services/MetaPlanner.php`](../backend/app/Services/MetaPlanner.php). |
| `tenant.automation_level.set` emitted on onboarding + settings change | ✅ | [`OnboardingController`](../backend/app/Http/Controllers/Admin/OnboardingController.php). |
| Soft gate **`override_policy`** wired into Atlas payloads | ✅ | [`backend/app/Http/Controllers/AtlasController.php`](../backend/app/Http/Controllers/AtlasController.php). |

> **Operational note:** validate STE projections populate `ste_tenant_states` via deployed workers + seeded mappings in each environment (`php artisan migrate --force` then STE jobs).

Pull requests correlate with merges touching the paths above (`git log -- backend/app/Http/Controllers/Admin/OnboardingController.php`).

---

### Phase 2 — Hannah Guidance Loop ✅ (MVP UX — hardened analytics still Phase 4+)

**Goal:** Close the loop between onboarding completion and first automation.

#### Phase 2 checklist → implementation traces

| Criterion | Status | Where it lives |
|-----------|--------|----------------|
| Landing on Atlas after onboarding exposes **guided next-step buttons** | ✅ | [`cockpit/src/components/HannahGuidancePanel.vue`](../cockpit/src/components/HannahGuidancePanel.vue) surfaced from [`cockpit/src/views/Atlas.vue`](../cockpit/src/views/Atlas.vue) when `?seed=onboarding` (or `?hannah=1`). |
| Seeded conversational kickoff | ✅ | [`cockpit/src/views/Atlas.vue`](../cockpit/src/views/Atlas.vue) auto-sends *“Help me get started after onboarding.”* once per session (sessionStorage guard). |
| Each tap forwards a structured natural-language intent to Atlas | ✅ | Guidance panel emits `run-command` → `atlasStore.sendMessage`. |
| Auth redirect honours **return_to** + unfinished onboarding | ✅ | [`cockpit/src/views/Login.vue`](../cockpit/src/views/Login.vue) (`postAuthRedirect`). |
| `initSession()` exists on Atlas store (was implicitly missing) | ✅ | [`cockpit/src/stores/atlas.js`](../cockpit/src/stores/atlas.js). |

Still **open / stretch** versus original vision:

| Stretch item | Tracking |
|--------------|----------|
| One-tap Flow creation DAG wiring from Hannah buttons | Forge / Nexus integration — evolve via Atlas command contracts |
| Dedicated STE markers `first_action_ready` / `flow_running` surfaced in cockpit heatmap | Platform STE dashboards — backlog |

---

### Phase 3 — Feature Packs v1 ✅ (staging + CLI — registry API backlog)

**Goal:** Ship installer + validation for the first vertical pack and align runtime expectations with dynamic agents.

| Component | Status | Reference |
|-----------|--------|-----------|
| Pack manifest schema + docs | ✅ | `packages/feature-packs/schema/` · [`docs/feature-packs/SPEC.md`](feature-packs/SPEC.md) · [`docs/feature-packs/LIFECYCLE.md`](feature-packs/LIFECYCLE.md) |
| Real-estate skeleton | ✅ | [`packages/feature-packs/real-estate-crm/`](../packages/feature-packs/real-estate-crm/) |
| **`DynamicAgent`** runtime | ✅ | [`intelligence/agents/dynamic_agent.py`](../intelligence/agents/dynamic_agent.py) |
| Pack manifest validator | ✅ | [`scripts/validate_feature_pack.py`](../scripts/validate_feature_pack.py) · `php artisan spidernet:pack-validate` |
| Pack staging installer (`spidernet:pack-install`) | ✅ | [`backend/app/Console/Commands/SpidernetPackInstall.php`](../backend/app/Console/Commands/SpidernetPackInstall.php) |
| Pack registry REST API (`/api/feature-packs`) | ⏳ Planned | Needed for SaaS catalogue UX |
| Formal signature verification (`spec.signatures`) | ⏳ Planned | Validator currently structural only |
| Automated tenant agent upsert + pack STE namespaces | ⏳ Planned | Hook installer to provisioning pipeline |

CLI equivalents of roadmap shorthand:

```bash
php artisan spidernet:pack-validate
php artisan spidernet:pack-install real-estate-crm --force
```

---

### Phase 4 — Cross-Tenant Learning

**Goal:** Enable packs to learn from all tenants using them, with privacy-preserving aggregation.

**Why this matters:** Each vertical has common patterns. A real estate agent in Miami and one in Seattle both benefit from "follow up within 5 minutes," but neither should expose their client list.

**Deliverables:**

| Component | Status | Reference |
|---|---|---|
| Shared bandit pool per pack | ⏳ Phase 4 | `shared: true` policy aggregation |
| Privacy-preserving aggregation | ⏳ Phase 4 | `keys_hash` + `field_count` (from Phase 1) |
| Transfer learning priors | ⏳ Phase 4 | New tenants start with pooled priors |
| Pack performance dashboard | ⏳ Phase 4 | Cross-tenant analytics (anonymized) |
| Differential privacy layer | ⏳ Phase 4 | Laplace noise on shared aggregates |

**Success Criteria:**
- New tenant with Real Estate CRM sees "system-optimized" defaults on day 1
- `atlas_copy_variants` table shows `pack_id` rows with pooled alpha/beta
- No PII (emails, names, addresses) in shared pool
- Performance improves 10%+ within 30 days without manual tuning

---

### Phase 5 — Autonomous Mode GA

**Goal:** Full "Autonomous" control mode is the default for new tenants, with weekly digest replacing daily cockpit use.

**Why this matters:** The ultimate value proposition: the owner interacts 5–10 minutes per week while the system runs 100+ decisions per day, continuously improving.

**Deliverables:**

| Component | Status | Reference |
|---|---|---|
| Autonomous mode as default | ⏳ Phase 5 | `tenants.automation_level` default |
| Weekly digest email | ⏳ Phase 5 | Summary of decisions, outcomes, next week preview |
| Exception-only notifications | ⏳ Phase 5 | Only page owner on hard failures |
| Autonomous guardrails | ⏳ Phase 5 | Budget caps, approval gates for irreversible actions |
| Autonomous performance SLOs | ⏳ Phase 5 | <10 min/week owner time, >100 decisions/day |

**Success Criteria:**
- 80% of tenants on Real Estate CRM run Autonomous mode
- Weekly digest open rate > 60%
- Average owner cockpit time < 10 minutes/week
- System-initiated actions: 100+ per day per tenant
- Human-initiated actions: < 5 per day per tenant

---

### Lead-to-Sale Funnel Bundle (2026-Q3, sales-crm pack v0.2.0)

**Goal:** A purchasable bundle that runs the entire lead-to-sale funnel over email and WhatsApp, using the tenant's brand and business data as context, gated on an owner-approved sales script.

**Why this matters:** Proves out the "purchase → discovery interview → owner-approved artifact → autonomous execution" pattern generally, not just for sales — the same shape (`FunnelSetupService`, `ApprovalEngine`, `pack_entitlements`) is reusable for any future pack that needs a setup wizard before it can run unattended. Also the first productization of the Priestley Five A's operating model (`docs/internal/operating-model.md`) as a tenant-facing feature, not just an internal process.

| Milestone | Ships | Reference |
|---|---|---|
| M1 | `sales-crm` pack content (agents, flows, state model), `leads`/`deals` tables, pipeline board UI | `packages/feature-packs/sales-crm/`, `app/Services/Sales/LeadService.php` |
| M2 | Discovery interview → script draft → owner approval → go-live pipeline | `app/Services/Sales/FunnelSetupService.php`, `funnel_setups`/`sales_scripts` tables |
| M3 | Email + WhatsApp channels, nurture sequences, inbox | `app/Services/Messaging/`, `app/Jobs/ProcessSequenceStepsJob.php` |
| M4 | Dodo Payments purchase flow + entitlement gating | `app/Services/Integrations/DodoPaymentsAdapter.php`, `pack_entitlements` table |
| M5 | Priestley Five A's productized for tenants | `app/Http/Controllers/Operating/OperatingController.php`, cockpit `/operating` |
| M6 | Internal process docs + regression tests | `docs/internal/{operating-model,3-1-90,awareness-list,asset-register}.md` |

**Cross-cutting fixes landed alongside this work:** the `/api/approvals` approve/reject 500 (wrong column names — this was the platform's core human-in-the-loop mechanism, not sales-crm-specific), dynamic-agent slug collisions across packs, an `ste_transitions`/`ste_event_mapping` column width that couldn't hold pack-namespaced chain names, and a `phpunit.xml` cache-store misconfiguration that silently defeated the CiFast suite's "no Redis" guarantee. Full list: `docs/internal/awareness-list.md`.

## Non-Goals

The following are explicitly out of scope to maintain focus:

1. **Replacing the 6 core agents** — They remain the foundation; vertical agents are dynamic configs.
2. **Building a marketplace UI before Phase 3** — The runtime must exist before discovery UX.
3. **Guaranteeing specific business outcomes** — We guarantee the *optimization loop*, not conversion rates.
4. **Multi-tenant data sharing beyond pack-scoped policies** — No cross-vertical learning, no raw data sales.
5. **Custom code injection in packs** — Packs are YAML configs only; no arbitrary Python/PHP execution.

---

## References

| Document | Purpose |
|---|---|
| `docs/adr/0001-agent-roster-reconciliation.md` | Core vs dynamic agent definitions |
| `docs/feature-packs/SPEC.md` | Pack manifest schema and semantics |
| `docs/feature-packs/LIFECYCLE.md` | Pack installation state machine |
| `docs/feature-packs/EXAMPLE-real-estate-crm.md` | Worked example pack |
| `.windsurf/plans/onboarding-phase-1-13bd6d.md` | Phase 1 detailed plan |
| `.windsurf/plans/vision-productization-all-four-13bd6d.md` | Combined artefacts plan |
| `docs/internal/inference-plan.md` | Inference plane rollout + scaling pointers |
| `docs/internal/operating-model.md` | Priestley Five A's applied to running this project |
| `docs/internal/3-1-90.md` | Project-level 3-year / 1-year / 90-day targets |
| `docs/internal/awareness-list.md` | Open + resolved cross-cutting findings |
| `docs/internal/asset-register.md` | Durable assets shipped per cycle |

---

## Glossary

| Term | Definition |
|---|---|
| **STE** | State Transition Engine — probabilistic Markov chains over `event_log` |
| **Feature Pack** | Versioned, signed bundle of `{state-model, agents, flows, policies}` |
| **Dynamic Agent** | Agent whose behavior is YAML-configured, runtime-class is `DynamicAgent` |
| **Control Mode** | `manual` (suggest), `assisted` (suggest+confirm), `autonomous` (act) |
| **Soft Gate** | STE-aware policy reshaping that guides users without blocking |
| **Thompson Sampling** | Bandit algorithm balancing exploration vs exploitation |
| **Cross-Tenant Learning** | Privacy-preserving aggregation of outcomes across tenants |

---

## Revision History

| Date | Change | Author |
|---|---|---|
| 2026-04-22 | Initial roadmap, Phases 0–5 | SpiderNet Core Team |
| 2026-05-02 | Closed Phase 1–3 checklist w/ repo pointers + noted CI/pgvector prerequisites | SpiderNet Core Team |
| 2026-07-09 | Added Lead-to-Sale Funnel Bundle (sales-crm v0.2.0), 90-day cycle cadence, Priestley operating-model docs | SpiderNet Core Team |
