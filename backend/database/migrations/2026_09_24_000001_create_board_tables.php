<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The board of advisors (plan D6 §6): five archetype seats plus a chairman,
 * run as a converged protocol — isolated independent takes, then an
 * anonymised cross-exam, then a chairman synthesis with the minority report
 * carried verbatim.
 *
 * Three tables because the three artefacts have different lifetimes: the
 * session is the question, a take is one seat's view at one round (kept so a
 * verdict can always be traced back to who said what, before anonymisation),
 * and the verdict is what the founder actually reads.
 *
 * SQLite-safe: plain Blueprint calls, string jsonb defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('opened_by')->nullable();

            $table->string('slug', 120);
            $table->text('question');
            // The brain files the brief was built from, with their versions.
            $table->jsonb('brief')->default('{}');
            // Seats actually sitting, in order.
            $table->jsonb('seats')->default('[]');

            // open | round_1 | round_2 | synthesis | complete | failed | cancelled
            $table->string('status', 16)->default('open');
            $table->unsignedTinyInteger('round')->default(0);

            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('tokens')->default(0);
            $table->string('brain_path', 255)->nullable();
            $table->text('error')->nullable();
            $table->jsonb('meta')->default('{}');

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('board_takes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('board_sessions')->cascadeOnDelete();

            $table->string('seat', 40);
            $table->unsignedTinyInteger('round')->default(1);
            // The label this seat wore in the anonymised round: A, B, C...
            $table->string('anon_label', 4)->nullable();

            // VerdictSchema: stance, confidence, one_number, what_would_change_my_mind,
            // kill_criteria[], reasoning.
            $table->jsonb('verdict')->default('{}');
            $table->longText('raw')->nullable();

            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('tokens')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'seat', 'round']);
            $table->index(['tenant_id', 'session_id']);
        });

        Schema::create('board_verdicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('board_sessions')->cascadeOnDelete();

            $table->text('consensus');
            // One row per seat: stance, confidence, one_number.
            $table->jsonb('table_rows')->default('[]');
            // Carried verbatim from the dissenting seat — never paraphrased.
            $table->text('minority_report')->nullable();
            $table->string('minority_seat', 40)->nullable();

            $table->text('recommended_action');
            $table->date('next_check_date')->nullable();
            $table->jsonb('kill_criteria')->default('[]');
            // Set when the recommendation became an approval (resource board_verdict).
            $table->uuid('approval_id')->nullable();

            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->unique('session_id');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_verdicts');
        Schema::dropIfExists('board_takes');
        Schema::dropIfExists('board_sessions');
    }
};
