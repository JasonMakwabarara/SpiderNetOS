# ADR-0002: PHP Skill Runtime, DB-backed Business Brain, Per-Agent Workspaces

**Status:** Accepted
**Date:** 2026-09-16
**Author:** SpiderNet Core Team
**Supersedes in part:** ADR-0001 ("Dynamic agents for verticals" — the Python `DynamicAgent` path)
**Program plan:** Brain, Workspaces, Skills & Integrations (2026-09-15/16)

## Context

SpiderNet's "three brains" idea has been under-used: all business context sits in Postgres
tables that no prompt reads, agents run one at a time through a single BLPOP loop, and the
config-driven Python runtime ADR-0001 chose for vertical agents has **never run** —
`intelligence/agents/dynamic_agent.py` cannot be instantiated (constructor, `AgentResult`,
`MemoryGraph` and `CostGovernor` signatures all mismatch `agent_base.py`). Pack YAML
`triggers`, per-capability `tools` and `cost_budget` are dead config.

What does work is PHP: `FeatureFlag`, `CostGovernor`, `ApprovalEngine`, `ConnectorManager`,
`MessageDispatchService`, `EventStore`, and the one production "agent" —
`Outreach/Bot/RecruiterBot` (FACTS prompt + `ReplyPostFilter` + approval row) — which
bypasses the Python runtime entirely. SQLite feature tests with `Http::fake('*/generate')`
already prove that harness end to end.

Jason's asks (2026-09-15/16): a brain that reads the business from files and folders, a
workspace **per agent** where every agent runs at once on the same context, tool calls wired
to where the work happens, ~43 skills with full-screen cards, and the six core characters
kept and expanded.

## Decisions

### D1. The skill runtime is PHP on Laravel queues; the Python `DynamicAgent` path is retired

- Skills run as `RunSkillJob` on a dedicated Horizon queue `agents` (`agents-supervisor`,
  4 processes local / 12 production, timeout 300). Concurrency comes from Horizon; a
  `TenantRunSlot` middleware (`Redis::funnel`, `config('agents.max_concurrent_per_tenant')`)
  caps a tenant, and `ShouldBeUnique` by run id prevents double execution.
- Hard Rule #2 is kept: runs start via `MetaPlanner::dispatchRun()` (cost gate, capability
  check, `agent.dispatched` event with `metadata.runtime='php_skill'`).
- The Python plane keeps the six core agents plus `/generate`, `/embed`, `/v1/classify`;
  native JSON-schema `tools` on `/generate` arrive in Phase 4. The `agent:dispatch` Redis list
  is left to core agents. The two producers that only `Redis::publish`ed to it
  (`VoiceController::triggerPostCallProcessing`, `TelephonyService::dispatchVoiceIntent`) now
  `lpush` the same envelope `MetaPlanner::dispatch()` uses (top-level `tenant_id`, `agent_id`),
  because the consumer BLPOPs a list and pub/sub was silently dropped.
- The live dead-end (`ConversationReplyBridgeProjection` → `sales_crm_crm conversation_reply`)
  is re-pointed to the `follow-up-drafter` skill via `SkillTriggerProjection` in PR 2.
- Every gate stays in PHP and generalises the `VoiceSafetyGuard` shape:
  `ToolGateway::call()` = flag → allowlist → autonomy ladder + `ApprovalEngine` → `RunBudget`
  + `CostGovernor` → connector connected → execute → trace + events.
- **Draft-only writes.** No agent tool ever sends or publishes. Writes return artifacts or
  proposals; `ApprovalEngine` executes them through `ArtifactApplier` (Google's own Gmail MCP
  ships `create_draft` and no `send` — same rule).

### D2. The Knowledge brain is a versioned, DB-backed virtual filesystem with folder semantics

- Not a git repository per tenant (multi-container deploy, SQLite CI, no shell in the web
  tier). Git-style behaviour is modelled in tables: `brain_files` (head per path, unique
  `(tenant_id, path)`), `brain_file_versions` (append-only), `brain_proposals`
  (`base_version` conflict → 409), `BrainSnapshot` (path → version pinned per run), zip
  export/import of the folder layout.
- The canonical layout is declared in `packages/brain/manifest.yaml`: paths, required
  sections, `min_chars`, the question Atlas asks when a section is missing,
  `interview_question_id` mappings to the sales-crm discovery interview,
  `stale_after_days`, and `data_class` (public | internal | confidential | personal) that
  `EgressGuard` honours before any sync to Hannah AI, social or ZetKai.
- Rule: structured facts stay in their tables and are projected into frontmatter; prose in
  the file body is the agents' source of truth. `BrainSyncService` owns `<!-- managed -->`
  blocks and never overwrites human prose outside them.
- **The brain learns the people, especially the user.** `people/user.md` (personal) and
  `people/team/<slug>.md` are first-class manifest paths, filled by the interview, by
  "remember this" in Atlas chat, and by inferred proposals from behaviour that a human
  approves. Every skill prompt gets a compact PEOPLE block, user first.
- Atlas reads the brain before every reply (`AtlasBrainContext` → BRAIN block, flag
  `atlas.brain_context`). `memory_nodes` embeddings are a pgsql-only accelerator
  (`BrainIndexer`, flag `brain.embed`); the LIKE fallback keeps SQLite green.

### D3. Each agent has its own persistent workspace; runs are sessions inside it

- `agent_workspaces`: one row per tenant × runnable identity, created on first skill enable
  (`status idle|working|needs_attention|needs_review|paused`, `pinned_brain_paths`,
  agent-private `scratch` exposed as `workspaces/<agent>/scratch/*.md`, `drafts_root`, a
  daily budget and today's spend, last run and heartbeat). Every `agent_runs` row carries
  `workspace_id`. All of a tenant's workspaces run concurrently on the same Knowledge brain;
  only the shared brain is write-guarded (proposals + approvals) — scratch is the agent's own.
- A run = the workspace's scratch + a pinned `BrainSnapshot` + its `agent_artifacts` (drafts)
  + `brain_proposals` + tool grants (card allowlist ∩ `tenant_skills.tool_overrides`) + the
  workspace budget. Replay idempotency: partial unique `(tenant_id, skill_slug, trigger_ref)
  WHERE trigger_ref IS NOT NULL`. Leases (`claimed_by`, `lease_expires_at`,
  `config('agents.lease_seconds')`) let `FailStaleAgentRunsJob` free stuck slots.
- Physical isolation is the default (Paperclip issue #3335: shared working dirs silently
  lost work). Binary spill-over lives on the `agent-workspaces` disk under
  `<tenant>/<workspace>/`; brain exports on `brain-exports`.
- The autonomy ladder is per skill (`tenant_skills.autonomy_level human_led | assisted |
  autonomous`, plus `shadow` behind `agents.shadow_mode`), promoted by the one existing gate
  (20 clean drafts, `clean_drafts_count`) so "autonomous" means one thing across the OS.
- The God's Eye board lists workspaces, not just runs — Superset's Working / Needs attention /
  Needs review board, server-side, without the globe.

### The three brains (naming, confirmed by Jason 2026-09-15)

| Brain | What it is | Where it lives |
|---|---|---|
| **Knowledge brain** | What the business *is* and *who is in it* — files & folders, the user above all | `brain_files` / `brain_file_versions` / `brain_proposals` (D2) |
| **Operating brain** | How work *gets done* — six core characters, MetaPlanner, skills runtime, workspaces, tool gateway, approvals | `agent_workspaces` / `tenant_skills` / `agent_runs` / `agent_run_steps` / `agent_artifacts`, `ToolGateway`, `ApprovalEngine` (D1, D3) |
| **Learning brain** | What *works*, what is *happening outside*, and whether it holds up when tested | `skill_outcomes`, `experiments`, `memory_nodes`, `event_log`, `market/**`, board sessions (later PRs) |

Every run reads the Knowledge brain, executes through the Operating brain, writes outcomes
back to the Learning brain — and the Learning brain tests them before a learned rule changes a
default. No previous code or doc used the term; from here on the cockpit, the docs and the
map's core node do.

### Skills, identities and the six characters

- Skills are folders (`packages/skills/<slug>/{SKILL.md, card.yaml, prompts/task.md,
  tools.yaml?}`) validated by `packages/skills/_schema/skill-card.schema.json` and seeded into
  the global `skills` / `skill_relations` tables. Identities map to `agents` rows in
  `packages/skills/identities.yaml` (needed because pack agents are `<pack>_<agent>`,
  `compliance` merges two pack agents and `recruiter_bot` is a service). Every identity and
  every card links to exactly one core character (`reports_to`, `core_agent`); the chain
  skill → identity → character → Atlas is seeded into `agent_delegation_edges`.
- ADR-0001's "six core agents remain" stands; they are expanded, not renamed. Character
  sheets live in `packages/core-agents/<slug>/CHARACTER.md` and are layered under the identity
  prompt.

## Name collisions (documented so nobody "fixes" them)

1. **`hannah` (core character) vs `hannah_ai` (the product).** The core guide/teacher
   character keeps slug `hannah` (Jason's call — no rename). The external marketing product at
   hannah-ai.world is **always** `hannah_ai`: connector id `hannah_ai`, tool prefix
   `hannah_ai_*`, flags `hannah_ai.*`, tenant slug `hannah-ai`, card refs `product:hannah_ai`.
   `AtlasIntentCompiler` routes "market this product / campaign / Hannah AI" to the
   `campaign-brief-handoff` skill and "help me / teach me / how do I" to the `hannah`
   character; a test asserts neither leaks into the other. `HannahGuidancePanel.vue` is the
   character and stays.
2. **Atlas vs Hannah AI's "Atlas workflows".** Hannah AI's own codebase (`Hannah-VS-main`)
   contains a planner named Atlas ("Atlas workflows"). It is unrelated to SpiderNet's Atlas
   character and to ZetKai's Atlas (the AI inside ZetKai, which converges on the same
   inference routing but keeps its own store). Cross-repo docs must qualify which Atlas they
   mean; no code path bridges them by name.

## Consequences

**Positive**
- The runtime is testable on SQLite in CI today (`Http::fake('*/generate')`), reuses every
  existing gate, and gains concurrency from Horizon for free.
- Business context becomes human-readable, versioned, exportable and diffable; every run pins
  the exact brain it read.
- Agents run in parallel without sharing working memory; the shared brain stays consistent
  because it only changes through proposals and approvals.

**Negative / accepted costs**
- Two runtimes for a while: PHP skills and the six Python core agents. The Python
  `DynamicAgent` class and its pack agent loading are dead code to remove once `SkillRegistry`
  covers the packs (`agents.config.skills[]`).
- A DB-backed brain has no `git blame`/branching; a real DVCS could be added later behind
  `BrainStore` if a tenant needs offline editing.
- Per-agent workspaces multiply rows (tenant × identity) and need a heartbeat to stay honest
  about status; `FailStaleAgentRunsJob` and the lease are mandatory, not optional.

## Rollout

Everything ships flag-off (`agents.*`, `atlas.brain_context`, …) except `brain.enabled` and
`brain.embed`; enable per tenant via `FeatureFlag` starting with `hannah-ai` and Jason's own
tenant, after the `agents:doctor` preflight. See the plan's "Phased delivery" for PR 0–8.
