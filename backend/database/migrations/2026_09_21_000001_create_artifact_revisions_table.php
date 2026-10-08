<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Every edit is a lesson" (plan D8 #1): the original body is kept next to
 * the edited/sent body for every approval edit, reject or reclassify of an
 * agent artifact or an outreach conversation message, with a normalised edit
 * distance and deterministic categories. clean_drafts_count is redefined as
 * distance < 0.05 (RevisionRecorder::isClean); DistilCorrectionsJob turns
 * recurring categories into brain proposals.
 *
 * SQLite-safe: plain Blueprint calls, string jsonb defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifact_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            // agent_artifact | conversation_message
            $table->string('subject_type', 32);
            $table->string('subject_id', 64);
            $table->uuid('user_id')->nullable();
            // edit | reject | reclassify
            $table->string('action', 16)->default('edit');
            // Skill that produced the original (from meta) — DistilCorrectionsJob groups on it.
            $table->string('skill_slug', 64)->nullable();

            $table->longText('original_body');
            $table->longText('edited_body');
            // Normalised edit distance in [0, 1].
            $table->decimal('distance', 6, 4)->default(0);
            // Subset of facts|links|numbers|length|tone|ask.
            $table->jsonb('categories')->default('[]');
            // Optional "why?" chip the reviewer picked.
            $table->string('why', 160)->nullable();
            $table->jsonb('meta')->default('{}');

            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['tenant_id', 'skill_slug', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_revisions');
    }
};
