<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Operating brain (ADR-0002, plan D3): one persistent workspace per
 * tenant x runnable identity, the per-tenant skill ladder, runs that happen
 * inside a workspace, their step trace and the draft artifacts they produce.
 * Agents never write the shared brain or send anything directly — artifacts
 * and proposals are applied by ApprovalEngine.
 *
 * SQLite-safe: plain Blueprint calls, string jsonb defaults, the one partial
 * unique index via DB::statement (both sqlite and pgsql accept the syntax).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('agent_id');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');

            // Identity slug (growth, crm, richard...) — also the folder name
            // under workspaces/<slug>/ in the brain tree and on the
            // agent-workspaces disk.
            $table->string('slug', 64);
            // idle | working | needs_attention | needs_review | paused
            $table->string('status', 24)->default('idle');

            // Brain paths this agent always reads first (skill card brain.reads ∪ tenant pins).
            $table->jsonb('pinned_brain_paths')->default('[]');
            // Agent-private notes and working memory, keyed by relative file
            // name; exposed read-only as workspaces/<slug>/scratch/*.md.
            $table->jsonb('scratch')->default('{}');
            // Where artifacts land: workspaces/<slug>/drafts
            $table->string('drafts_root', 255);

            $table->decimal('budget_daily_usd', 10, 4)->default(2.0);
            $table->decimal('spent_today_usd', 10, 4)->default(0);
            // Day the spent_today_usd counter belongs to (reset on rollover).
            $table->date('spent_day')->nullable();

            $table->uuid('last_run_id')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();

            $table->unique(['tenant_id', 'agent_id']);
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_skills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('skill_slug', 64);
            // The identity that runs it (agents row) and its workspace; null
            // for route/interview/service run kinds that need no workspace.
            $table->uuid('agent_id')->nullable();
            $table->uuid('workspace_id')->nullable();

            $table->boolean('enabled')->default(false);
            // human_led | assisted | autonomous (| shadow when agents.shadow_mode)
            $table->string('autonomy_level', 16)->default('human_led');
            // Per-tenant additions/removals over the card's tools[] allowlist.
            $table->jsonb('tool_overrides')->default('{}');
            $table->decimal('budget_daily_usd', 10, 4)->nullable();
            // Skill-private state (cursors, last run summary, learned rules).
            $table->jsonb('state')->default('{}');
            // Promotion gate counter (the outreach "20 clean drafts" rule).
            $table->unsignedInteger('clean_drafts_count')->default(0);

            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'skill_slug']);
            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            // Every run is a session inside a workspace (null only for
            // service/route kinds that never touch the runtime).
            $table->uuid('workspace_id')->nullable();
            $table->foreign('workspace_id')->references('id')->on('agent_workspaces')->onDelete('set null');
            $table->uuid('agent_id')->nullable();

            $table->string('skill_slug', 64);
            // single_shot | agentic
            $table->string('mode', 16)->default('single_shot');
            // manual | event | scheduled | atlas | api | resume
            $table->string('trigger_type', 16)->default('manual');
            // Replay-idempotency key: event id, cron:{slug}:{minute}, api idempotency key...
            $table->string('trigger_ref', 190)->nullable();
            $table->uuid('triggered_by')->nullable();
            $table->uuid('parent_run_id')->nullable();

            // queued | claimed | running | waiting_approval | waiting_input |
            // blocked | succeeded | failed | cancelled
            $table->string('status', 24)->default('queued');

            $table->jsonb('inputs')->default('{}');
            // Includes next_steps[] (plan D8 "one step further" contract).
            $table->jsonb('outputs')->default('{}');
            // Loop state for agentic runs (messages, pending tool call, iteration).
            $table->jsonb('state')->default('{}');
            // path => version pinned at claim time (BrainSnapshot).
            $table->jsonb('brain_snapshot')->default('{}');
            // Gap questions when status=blocked: [{path, section, question}].
            $table->jsonb('questions')->default('[]');

            $table->unsignedInteger('tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);

            // Worker lease (FailStaleAgentRunsJob fails runs past lease_expires_at).
            $table->string('claimed_by', 128)->nullable();
            $table->timestamp('lease_expires_at')->nullable();

            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'skill_slug', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['status', 'lease_expires_at']);
        });

        // Replay idempotency: the same event/cron tick never starts a second
        // run of the same skill for a tenant. Partial so manual/api runs
        // (trigger_ref NULL) are unlimited.
        DB::statement('
            CREATE UNIQUE INDEX agent_runs_tenant_skill_trigger_unique
              ON agent_runs (tenant_id, skill_slug, trigger_ref)
              WHERE trigger_ref IS NOT NULL
        ');

        Schema::create('agent_run_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('run_id');
            $table->foreign('run_id')->references('id')->on('agent_runs')->onDelete('cascade');

            $table->unsignedInteger('seq');
            // prompt | model | tool_call | tool_result | validator | approval | note | error
            $table->string('kind', 24);
            // Tool name, validator name, model id...
            $table->string('name', 128)->nullable();
            $table->jsonb('input')->default('{}');
            $table->jsonb('output')->default('{}');
            // ok | denied | failed | pending
            $table->string('status', 16)->default('ok');
            $table->unsignedInteger('tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'seq']);
        });

        Schema::create('agent_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('run_id')->nullable();
            $table->foreign('run_id')->references('id')->on('agent_runs')->onDelete('set null');
            $table->uuid('workspace_id')->nullable();
            $table->string('skill_slug', 64)->nullable();

            // draft_email | draft_sequence | draft_reply | caption | report |
            // note | classification | meeting_proposal
            $table->string('kind', 24);
            // Location in the workspace drafts folder, e.g. workspaces/growth/drafts/acme-step1.md
            $table->string('path', 255)->nullable();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            // Kind-specific structure (steps[], variants, slots[], classification...).
            $table->jsonb('meta')->default('{}');

            // draft | submitted | approved | rejected | applied
            $table->string('status', 16)->default('draft');
            $table->uuid('approval_id')->nullable();
            // What ArtifactApplier produced (message_templates id, booking id, brain path@version...).
            $table->string('applied_ref', 190)->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'kind']);
            $table->index('run_id');
            $table->index('approval_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_artifacts');
        Schema::dropIfExists('agent_run_steps');
        DB::statement('DROP INDEX IF EXISTS agent_runs_tenant_skill_trigger_unique');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('tenant_skills');
        Schema::dropIfExists('agent_workspaces');
    }
};
