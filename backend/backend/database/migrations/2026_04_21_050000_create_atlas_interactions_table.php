<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * atlas_interactions
 *
 * B5 — behavioral logging schema powering Transformation Score (TS),
 * reward model training, and online adaptation.
 *
 * One row per Atlas chat response. Frontend behavior events update the
 * same row asynchronously.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_interactions', function (Blueprint $table) {
            $table->uuid('id')->primary();              // interaction_id
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();

            $table->text('user_input');

            $table->jsonb('parsed_intent');             // desired_future, pain_points, functional_goal, emotional_goal, task_type
            $table->jsonb('atlas_response');            // future_state, value, emotional_shift, action_summary, details
            $table->jsonb('execution_result');          // status, metrics, agent_used, cost_status
            $table->jsonb('generation')->nullable();    // style, source, violations, latency_ms

            // Behavior (updated async by frontend events)
            $table->boolean('clicked_expand')->default(false);
            $table->boolean('accepted_action')->default(false);
            $table->boolean('follow_up')->default(false);
            $table->integer('time_on_response_ms')->nullable();

            // Explicit feedback
            $table->smallInteger('rating')->nullable(); // -1, 0, +1
            $table->text('comment')->nullable();

            // Derived TS (computed by scheduled job)
            $table->decimal('value_perception_score', 6, 4)->nullable();
            $table->decimal('clarity_score',         6, 4)->nullable();
            $table->decimal('emotional_score',       6, 4)->nullable();
            $table->decimal('actionability_score',   6, 4)->nullable();
            $table->decimal('trust_score',           6, 4)->nullable();
            $table->decimal('cognitive_load_penalty',6, 4)->nullable();
            $table->decimal('technical_leakage_penalty', 6, 4)->nullable();
            $table->decimal('final_ts',              6, 4)->nullable()->index();

            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'final_ts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_interactions');
    }
};
