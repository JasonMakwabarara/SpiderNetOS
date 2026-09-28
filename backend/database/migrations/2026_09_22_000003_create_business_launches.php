<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-launch pack (plan D7 §5): one row per founder journey from
 * "Atlas, I want to start a business" to an approved, live plan.
 *
 *   purchased → interviewing → researching → modelling → drafted
 *             → awaiting_approval → approved → live
 *
 * interview_answers is keyed by question id (packages/feature-packs/
 * business-launch/interview/questions.yaml); stage_artifacts records each
 * stage commit (brain paths → versions, answers hash) and every generated
 * deliverable (finance model summary, plan formats, stored file paths).
 *
 * Plain Blueprint calls and string jsonb defaults only so the Feature suite
 * runs on sqlite :memory:.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_launches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('pack_id', 64)->default('business-launch');

            $table->string('status', 24)->default('purchased');
            $table->string('current_stage', 32)->nullable();

            $table->jsonb('interview_answers')->default('{}');
            $table->jsonb('stage_artifacts')->default('{}');

            // uk | za | zw (packages/feature-packs/business-launch/jurisdictions/<code>.yaml)
            $table->string('jurisdiction', 8)->nullable();

            $table->uuid('approval_id')->nullable();
            $table->timestamp('went_live_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pack_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_launches');
    }
};
