# SpiderNetOS Roadmap

**Version:** 3.2 → 4.0  
**Last Updated:** 2026-04-22  

## Thesis

SpiderNetOS is not a CRM, a workflow tool, or an AI assistant. It is a **self-optimizing business operating system** that an owner buys to improve their business without constant input. The owner spends 5–10 minutes per week reviewing outcomes; the system runs hundreds of decisions per day, continuously learning what works and converging toward better states.

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

### Phase 1 — Initial Conditions & Control Modes (In Progress)

**Goal:** Capture the initial-conditions vector for every tenant and expose the Manual/Assisted/Autonomous control primitive.

**Why this matters:** Without onboarding persistence, every tenant starts with random priors. Without control modes, the system cannot learn which autonomy level works for which business type.

**Deliverables:**

| Component | Status | Reference |
|---|---|---|
| Onboarding persistence (5 steps + observation) | 🔄 In Progress | `.windsurf/plans/onboarding-phase-1-13bd6d.md` |
| `automation_level` column and enforcement | 🔄 In Progress | MetaPlanner injection |
| STE onboarding chain (`tenant.onboarding_active`) | 🔄 In Progress | `SteEventMappingSeeder` extension |
| Soft gate (`override_policy`) | 🔄 In Progress | AtlasController wiring |
| HannahGuidancePanel | ⏳ Phase 2 | Action buttons for Atlas responses |
| First-login redirect | ⏳ Phase 2 | Router guard + seeded message |

**Success Criteria:**
- Admin completes 6-step wizard, lands on `/atlas?seed=onboarding`
- `ste_tenant_states` shows `tenant.onboarding_active → active` transition
- `automation_level` present in every `agent.dispatched` event metadata
- Control mode changes emit `tenant.automation_level.set` events

---

### Phase 2 — Hannah Guidance Loop

**Goal:** Close the loop between onboarding completion and first automation, with Hannah as the guide.

**Why this matters:** The moment after onboarding is the highest-risk drop-off point. Hannah must transform user intent into action, not just provide information.

**Deliverables:**

| Component | Status | Reference |
|---|---|---|
| `HannahGuidancePanel.vue` | ⏳ Not Started | Render contract + action buttons |
| First-login redirect | ⏳ Not Started | `onboarding_completed_at` check |
| Seeded Atlas message | ⏳ Not Started | `"Help me get started"` auto-send |
| Atlas action-space narrowing | ⏳ Not Started | Respect `override_policy` |
| Clickable command buttons | ⏳ Not Started | `action.command` → POST /atlas/chat |

**Success Criteria:**
- New user clicks "Finish" on onboarding → sees Hannah with 3 actionable next steps
- Each step has a one-click button that creates a Flow
- First Flow runs within 5 minutes of onboarding completion
- STE shows `onboarding_active → first_action_ready → flow_running` chain

---

### Phase 3 — Feature Packs v1

**Goal:** Ship the first vertical pack (Real Estate CRM) and the pack installer runtime.

**Why this matters:** The vision of "autonomous operating teams for every vertical" requires a packaging and distribution system. Real Estate is the reference vertical.

**Deliverables:**

| Component | Status | Reference |
|---|---|---|
| Pack manifest schema | ✅ Complete | `packages/feature-packs/schema/feature-pack.schema.json` |
| Pack specification | ✅ Complete | `docs/feature-packs/SPEC.md` |
| Pack lifecycle docs | ✅ Complete | `docs/feature-packs/LIFECYCLE.md` |
| Real Estate CRM skeleton | ✅ Complete | `packages/feature-packs/real-estate-crm/` |
| Pack installer runtime | ⏳ Phase 3 | `spidernet pack install` command |
| Pack validator | ⏳ Phase 3 | Schema + signature verification |
| DynamicAgent loader | ⏳ Phase 3 | Config → runtime agent instantiation |
| Pack-scoped STE chains | ⏳ Phase 3 | `pack.{id}.{chain}` namespace |
| Pack registry API | ⏳ Phase 3 | List, search, install endpoints |

**Success Criteria:**
- `spidernet pack install real-estate-crm` successfully installs
- Dynamic agents appear in tenant agent list
- Pack STE chains receive events and populate projections
- Pack can be uninstalled, leaving `event_log` intact

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
