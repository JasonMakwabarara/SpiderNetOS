<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B — Voice Agent Policy
 *
 * Adds tool_allowlist and approval_policy to voice_numbers if not yet present.
 * These columns were introduced as part of the Phase A hardening migration
 * (2026_04_22_000001_add_voice_hardening_columns.php).
 *
 * This migration is a NO-OP guard: it only applies the columns if the prior
 * migration has not yet run (e.g. when deploying Phase B to a fresh environment
 * before Phase A has been applied).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: only add columns if they don't exist (idempotent)
        if (! Schema::hasColumn('voice_numbers', 'tool_allowlist')) {
            Schema::table('voice_numbers', function (Blueprint $table) {
                $table->jsonb('tool_allowlist')->nullable()->after('is_active');
            });
        }

        if (! Schema::hasColumn('voice_numbers', 'approval_policy')) {
            Schema::table('voice_numbers', function (Blueprint $table) {
                $table->string('approval_policy', 16)->default('off')->after('tool_allowlist');
            });
        }

        // Agent mode config column on voice_numbers for per-number overrides
        if (! Schema::hasColumn('voice_numbers', 'agent_config')) {
            Schema::table('voice_numbers', function (Blueprint $table) {
                // Per-number LLM config overrides: model, temperature, max_tokens
                $table->jsonb('agent_config')->nullable()->after('approval_policy');
            });
        }
    }

    public function down(): void
    {
        Schema::table('voice_numbers', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('voice_numbers', 'agent_config')) {
                $columns[] = 'agent_config';
            }
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
        // tool_allowlist and approval_policy owned by Phase A migration — leave to its down()
    }
};
