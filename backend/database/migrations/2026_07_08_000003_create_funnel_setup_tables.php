<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnel_setups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('pack_id', 64)->default('sales-crm');
            $table->string('status', 24)->default('purchased');
            // Keyed by interview question id -> { question, answer, answered_at }.
            $table->jsonb('interview_answers')->default('{}');
            $table->string('current_section', 64)->nullable();
            $table->uuid('active_script_id')->nullable();
            $table->uuid('approval_id')->nullable();
            $table->timestampTz('went_live_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'pack_id']);
        });

        Schema::create('sales_scripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('funnel_setup_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft');
            // Per-channel, per-stage sections: { email: { opener, qualify, objections, close, followups: [...] }, whatsapp: {...} }
            $table->jsonb('content')->default('{}');
            $table->text('rationale')->nullable();
            $table->string('created_by', 64)->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('funnel_setup_id')->references('id')->on('funnel_setups')->cascadeOnDelete();
            $table->unique(['funnel_setup_id', 'version']);
        });

        Schema::table('funnel_setups', function (Blueprint $table) {
            $table->foreign('active_script_id')->references('id')->on('sales_scripts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('funnel_setups', function (Blueprint $table) {
            $table->dropForeign(['active_script_id']);
        });
        Schema::dropIfExists('sales_scripts');
        Schema::dropIfExists('funnel_setups');
    }
};
