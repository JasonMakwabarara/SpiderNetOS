<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas context threads (plan D8 #13): the durable session behind
 * POST/GET /api/atlas/sessions that the cockpit already calls. A thread
 * remembers what it is about (title, business), the brain paths pinned to
 * it, the runs it spawned, its open questions, the last summary and next
 * steps, and the "one more question" state that keeps Atlas from asking the
 * same thing twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable();

            $table->string('title')->nullable();
            // Which business this thread is about (multi-business founders).
            $table->string('business', 120)->nullable();

            $table->jsonb('pinned_brain_paths')->default('[]');
            $table->jsonb('spawned_run_ids')->default('[]');
            // [{question, source, path?, section?, asked_at, answered_at?}]
            $table->jsonb('open_questions')->default('[]');
            $table->text('last_summary')->nullable();
            // Mirrors agent_runs.outputs.next_steps[] of the last run in this thread.
            $table->jsonb('last_next_steps')->default('[]');
            // {turns, last_asked_turn, asked: {key: {asked_at, answered_at?, skipped_at?, count}}, asked_dates: {date: n}}
            $table->jsonb('one_more_question_state')->default('{}');

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'last_seen_at']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_threads');
    }
};
