<?php

/**
 * SpiderNet OS / Atlas — Feature Flag Registry
 *
 * Resolution order (highest wins):
 *   1. Redis key  feature:<flag>             → "on" | "off" | "fallback"
 *   2. Redis key  feature:<flag>:tenant:<id> → per-tenant override
 *   3. Environment variable  FEATURE_<UPPER_SNAKE>  → "on" | "off" | "fallback"
 *   4. Default value defined here            → bool / string
 *
 * Use App\Services\FeatureFlag::on()  /  ::value()  to read.
 * Use `php artisan feature:set <name> <value>` to override at runtime.
 */

return [

    // -----------------------------------------------------------------------
    // Blocker A — usage aggregate v2 rollout gates
    // -----------------------------------------------------------------------

    'atlas.usage_aggregates_v2' => env('FEATURE_ATLAS_USAGE_AGGREGATES_V2', 'off'),

    /**
     * 48-hour shadow mode: dual-write the canonical path alongside the legacy
     * path, log diffs to usage_shadow_diffs, but serve reads from legacy.
     */
    'atlas.usage_aggregates_v2.shadow' => env('FEATURE_ATLAS_USAGE_AGGREGATES_V2_SHADOW', 'off'),

    /**
     * Atomic flip: canonical writer only, canonical reader, v2 contract.
     * Set ONLY after shadow diff gate is clean (0 mismatches for ≥ 24 h).
     */
    'atlas.usage_aggregates_v2.cutover' => env('FEATURE_ATLAS_USAGE_AGGREGATES_V2_CUTOVER', 'off'),

    /**
     * Emergency revert — valid only during 72 h post-GA watch window.
     * Reverts writer to legacy path; requires DB rollback migration to be safe.
     */
    'atlas.usage_aggregates_v2.rollback' => env('FEATURE_ATLAS_USAGE_AGGREGATES_V2_ROLLBACK', 'off'),

    // -----------------------------------------------------------------------
    // Atlas copy surfaces
    // -----------------------------------------------------------------------

    'atlas.copy.empty_state'   => env('FEATURE_ATLAS_COPY_EMPTY_STATE', 'on'),
    'atlas.copy.banner'        => env('FEATURE_ATLAS_COPY_BANNER', 'on'),
    'atlas.copy.modal'         => env('FEATURE_ATLAS_COPY_MODAL', 'on'),
    'atlas.copy.tooltip'       => env('FEATURE_ATLAS_COPY_TOOLTIP', 'on'),
    'atlas.copy.success_state' => env('FEATURE_ATLAS_COPY_SUCCESS_STATE', 'on'),
    'atlas.copy.error_state'   => env('FEATURE_ATLAS_COPY_ERROR_STATE', 'on'),

    // -----------------------------------------------------------------------
    // Voice AI — Phase A/B/C/E feature flags
    // -----------------------------------------------------------------------

    /**
     * Global voice vertical kill-switch.
     * Set to 'off' to return static "unavailable" TwiML from all webhooks.
     * Kill: php artisan feature:set voice.inbound off
     */
    'voice.inbound' => env('FEATURE_VOICE_INBOUND', 'on'),

    /**
     * Route voice turns through the Python VoiceAgent (Phase B).
     * Default OFF until VoiceAgent is fully deployed.
     */
    'voice.agent_mode' => env('FEATURE_VOICE_AGENT_MODE', 'off'),

    /**
     * Enable the bidirectional Twilio Media Streams pipeline (Phase C).
     * Requires VoiceStreamController + inference voice_pipeline to be running.
     */
    'voice.streaming' => env('FEATURE_VOICE_STREAMING', 'off'),

    /**
     * Allow voice tools to be executed (Phase B+).
     * Off = tools are parsed but silently no-op.
     */
    'voice.tools' => env('FEATURE_VOICE_TOOLS', 'on'),

    /**
     * Enable post-call summarisation DAG via Nexus (Phase C).
     */
    'voice.post_call_summary' => env('FEATURE_VOICE_POST_CALL_SUMMARY', 'off'),

    /**
     * Enable Atlas bandit prompt selection for voice agents (Phase E).
     */
    'voice.atlas_prompts' => env('FEATURE_VOICE_ATLAS_PROMPTS', 'off'),

    // -----------------------------------------------------------------------
    // Atlas RL / bandit
    // -----------------------------------------------------------------------

    /**
     * Macro prompt-evolution cycle — default OFF, enable after D+7 when
     * sufficient baseline reward data has accumulated.
     */
    'atlas.prompt_evolution' => env('FEATURE_ATLAS_PROMPT_EVOLUTION', 'off'),

    /**
     * Bandit algorithm: "thompson" (default) | "epsilon_greedy" (emergency fallback).
     */
    'atlas.bandit.algo' => env('FEATURE_ATLAS_BANDIT_ALGO', 'thompson'),

    /**
     * Posterior temperature scalar — clamped server-side to [0.5, 1.2].
     * Values < 1.0 concentrate exploration; > 1.0 flatten the posterior.
     */
    'atlas.bandit.temperature' => (float) env('FEATURE_ATLAS_BANDIT_TEMPERATURE', 1.0),

    /**
     * Minimum impression count before an arm can be starved by exploitation.
     */
    'atlas.bandit.min_impressions_floor' => (int) env('FEATURE_ATLAS_BANDIT_MIN_IMPRESSIONS_FLOOR', 50),

    // -----------------------------------------------------------------------
    // State Transition Engine (STE) — plan §12
    // -----------------------------------------------------------------------

    /**
     * Gates the /api/ste/* read endpoints and the /platform/ste cockpit route.
     */
    'platform.ste_read' => env('FEATURE_PLATFORM_STE_READ', 'on'),

    /**
     * Kill-switch for the StateTransitionProjection projector itself.
     * "off" = accepts() returns false so EventStore::append() skips STE work.
     * Events are still written to event_log and can be backfilled later via
     * `php artisan ste:backfill --confirm`.
     */
    'platform.ste_projector' => env('FEATURE_PLATFORM_STE_PROJECTOR', 'on'),

    /**
     * Gates POST /api/ste/simulate — separate from ste_read so the Monte Carlo
     * endpoint can be throttled or disabled independently.
     */
    'platform.ste_simulate' => env('FEATURE_PLATFORM_STE_SIMULATE', 'on'),

    /**
     * Phase 2 — constraint-aware Thompson priors. Default OFF. Toggling this
     * on routes AtlasCopyController through the biased-select path.
     */
    'platform.ste_control' => env('FEATURE_PLATFORM_STE_CONTROL', 'off'),

    // -----------------------------------------------------------------------
    // Atlas "Enhance Prompt" affordance
    // -----------------------------------------------------------------------

    /**
     * Gates the /api/atlas/enhance-prompt endpoint and the cockpit
     * "Enhance" button. When off, the button is hidden and the endpoint
     * returns 503.
     */
    'atlas.enhance_prompt' => env('FEATURE_ATLAS_ENHANCE_PROMPT', 'on'),

    /**
     * OpenJarvis local-first augmentation for Atlas AI (background only — not user-facing).
     * @see docs/OPENJARVIS_INTEGRATION.md
     */
    'atlas.openjarvis' => env('FEATURE_ATLAS_OPENJARVIS', 'on'),

    /** Growth+ — morning digest / operator briefing via Atlas outcomes loop. */
    'atlas.jarvis.morning_digest' => env('FEATURE_ATLAS_JARVIS_MORNING_DIGEST', 'on'),

    /** Enterprise — deep multi-hop research agent (background Atlas augmentation). */
    'atlas.jarvis.deep_research' => env('FEATURE_ATLAS_JARVIS_DEEP_RESEARCH', 'on'),

];
