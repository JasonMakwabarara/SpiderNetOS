<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A — Voice Hardening
 *
 * Additive columns only; no column modifications or drops.
 * Safe to roll back via down().
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── voice_numbers additions ────────────────────────────────────────
        Schema::table('voice_numbers', function (Blueprint $table) {
            // Whether this number is permitted to place outbound calls
            $table->boolean('allow_outbound')->default(false)->after('is_active');

            // Maximum inbound calls per calendar day (null = unlimited)
            $table->unsignedSmallInteger('daily_call_cap')->nullable()->after('allow_outbound');

            // Approval policy for sensitive tools: 'off' | 'notify' | 'strict'
            $table->string('approval_policy', 16)->default('off')->after('daily_call_cap');

            // JSON array of permitted tool IDs, e.g. ["end_call","send_sms"]
            // null = allow all registered voice tools
            $table->jsonb('tool_allowlist')->nullable()->after('approval_policy');
        });

        // ── voice_calls additions ──────────────────────────────────────────
        Schema::table('voice_calls', function (Blueprint $table) {
            // Snapshot of feature-flag values at call start for audit
            $table->jsonb('tenant_flag_snapshot')->nullable()->after('metadata');

            // Per-call cost breakdown: {twilio_minutes: x, stt: x, tts: x, llm_turns: x}
            $table->jsonb('cost_breakdown')->nullable()->after('tenant_flag_snapshot');

            // Twilio error code or internal error code on failed calls
            $table->string('error_code', 64)->nullable()->after('cost_estimate');
        });
    }

    public function down(): void
    {
        Schema::table('voice_numbers', function (Blueprint $table) {
            $table->dropColumn(['allow_outbound', 'daily_call_cap', 'approval_policy', 'tool_allowlist']);
        });

        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropColumn(['tenant_flag_snapshot', 'cost_breakdown', 'error_code']);
        });
    }
};
