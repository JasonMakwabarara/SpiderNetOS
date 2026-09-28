<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AgentCircuitBreaker (plan D8 #6): one row per (tenant, scope, scope_id).
 * Scope tenant = "Pause everything", agent = "Pause Richard", skill = one
 * card, tool_risk = "Stop sends but keep drafting" (scope_id send /
 * irreversible). paused stops the scope entirely; demoted lets it keep
 * drafting but blocks send/irreversible tools and drops the skill one rung
 * down the autonomy ladder. Consulted first in ToolGateway::call() and
 * AgentRunner::claim() (PR 3 call sites).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_agent_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            // tenant | agent | skill | tool_risk
            $table->string('scope', 16);
            // agents.id or slug, skill slug, tool risk; null for scope=tenant.
            $table->string('scope_id', 64)->nullable();
            // running | demoted | paused
            $table->string('state', 16)->default('running');
            $table->text('reason')->nullable();
            // human | tripwire
            $table->string('tripped_by', 16)->default('human');
            $table->uuid('tripped_by_user_id')->nullable();
            $table->timestamp('tripped_at')->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->jsonb('meta')->default('{}');

            $table->timestamps();

            $table->index(['tenant_id', 'state']);
            $table->index(['tenant_id', 'scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_agent_states');
    }
};
