<?php

/**
 * SpiderNet OS — PHP skill runtime (ADR-0002).
 *
 * Runtime knobs for the Operating brain: the `agents` Horizon queue, per-tenant
 * concurrency, budgets, run leases, where skill folders and character sheets
 * live on disk, per-tool cost estimates and the circuit-breaker tripwires.
 * Every capability is additionally gated by config/features.php (`agents.*`).
 */

return [

    // Master switch mirrored by the `agents.runtime` feature flag; both must be
    // on for MetaPlanner::dispatchRun() to enqueue a RunSkillJob.
    'runtime_enabled' => env('AGENTS_RUNTIME_ENABLED', false),

    // Horizon queue the skill runtime consumes (see config/horizon.php
    // `agents-supervisor`).
    'queue' => env('AGENTS_QUEUE', 'agents'),

    // Redis::funnel slot per tenant (TenantRunSlot middleware). Every tenant's
    // workspaces run concurrently up to this many runs at once.
    'max_concurrent_per_tenant' => (int) env('AGENTS_MAX_CONCURRENT_PER_TENANT', 4),

    // Budgets (USD). The daily budget seeds agent_workspaces.budget_daily_usd
    // on provisioning; the per-run cap is enforced by RunBudget before every
    // model or tool call.
    'default_daily_budget_usd' => (float) env('AGENTS_DEFAULT_DAILY_BUDGET_USD', 2.0),
    'per_run_budget_usd' => (float) env('AGENTS_PER_RUN_BUDGET_USD', 0.25),

    // A claimed run must renew its lease within this window or
    // FailStaleAgentRunsJob marks it failed and frees the slot.
    'lease_seconds' => (int) env('AGENTS_LEASE_SECONDS', 300),

    // RunSkillJob timeout; must stay below the Horizon supervisor timeout (300).
    'job_timeout_seconds' => (int) env('AGENTS_JOB_TIMEOUT_SECONDS', 240),

    // Skill folders: packages/skills/<slug>/{SKILL.md, card.yaml, prompts/, tools.yaml?}
    // (same path convention as FeaturePackInstaller::packsRoot()).
    'skills_root' => rtrim((string) env('SKILLS_ROOT', dirname(base_path()).'/packages/skills'), '/'),

    // Character sheets for the six core characters:
    // packages/core-agents/<slug>/CHARACTER.md
    'core_agents_root' => rtrim((string) env('CORE_AGENTS_ROOT', dirname(base_path()).'/packages/core-agents'), '/'),

    // Canonical Knowledge-brain layout (paths, required sections, gap questions).
    'brain_manifest' => env('BRAIN_MANIFEST', dirname(base_path()).'/packages/brain/manifest.yaml'),

    // JSON Schema every card.yaml is validated against (`skills:validate`).
    'skill_card_schema' => env('SKILL_CARD_SCHEMA', dirname(base_path()).'/packages/skills/_schema/skill-card.schema.json'),

    // Identity → agents row mapping (`reports_to` core character).
    'identities_manifest' => env('SKILL_IDENTITIES', dirname(base_path()).'/packages/skills/identities.yaml'),

    // Curated quote bank for the C-Suite newsletter (zen | buddhist | stoic |
    // hopeful | lighthearted | proverb). One quote per issue, never repeated
    // within 26 issues for the same tenant.
    'quote_bank' => env('QUOTE_BANK', dirname(base_path()).'/packages/content/quotes.yaml'),

    // The six core characters every identity and skill reports to.
    'core_characters' => ['atlas', 'hannah', 'forge', 'sentinel', 'prism', 'nexus'],

    // Estimated cost per tool call in USD, charged against the run and the
    // workspace's daily budget before the call is made (ToolContract may
    // override with a live estimate). Model calls are metered separately by
    // token usage. Unknown tools fall back to `default`.
    'tool_costs' => [
        'default' => 0.002,

        'brain.read' => 0.0,
        'brain.search' => 0.001,
        'brain.propose_update' => 0.0,
        'drafts.save' => 0.0,
        'drafts.save_sequence' => 0.0,
        'drafts.submit_for_review' => 0.0,

        'inbox.list_unread' => 0.001,
        'inbox.flag' => 0.0,
        'crm.list_replies' => 0.001,
        'crm.classify_reply' => 0.005,
        'crm.update_stage' => 0.0,
        'calendar.propose_slots' => 0.002,
        'calendar.book' => 0.002,
        'messages.send' => 0.01,
        'reports.build_weekly' => 0.05,
        'sources.scan' => 0.02,
        'sources.dedupe' => 0.0,
        'sources.file_note' => 0.0,

        // Generated ConnectorActionTool calls (ConnectorRegistry::CATALOGUE).
        'connector.*' => 0.005,
    ],

    // AgentCircuitBreaker tripwires (D8 #6). Count-based rules demote a skill
    // one rung down the autonomy ladder rather than freezing the tenant;
    // `pause` stops the scope entirely until a human resumes it.
    'tripwires' => [
        // Three rejected approvals in a row for one skill → demote.
        'consecutive_rejected_approvals' => (int) env('AGENTS_TRIPWIRE_REJECTED_IN_A_ROW', 3),

        // SkillOutputValidator reject rate over the window → demote.
        'validator_reject_rate' => (float) env('AGENTS_TRIPWIRE_VALIDATOR_REJECT_RATE', 0.30),
        'validator_window_runs' => (int) env('AGENTS_TRIPWIRE_VALIDATOR_WINDOW', 20),

        // Bounce rate on sends (the existing outreach auto-pause rule) → pause sends.
        'bounce_rate' => (float) env('AGENTS_TRIPWIRE_BOUNCE_RATE', 0.05),
        'bounce_min_sends' => (int) env('AGENTS_TRIPWIRE_BOUNCE_MIN_SENDS', 20),

        // Spend: a workspace over its daily budget is paused until midnight.
        'daily_budget_action' => 'pause',

        // How long a tripped scope stays demoted/paused before auto-resume
        // (null = manual resume only).
        'resume_after_minutes' => env('AGENTS_TRIPWIRE_RESUME_AFTER_MINUTES', 720),
    ],

];
