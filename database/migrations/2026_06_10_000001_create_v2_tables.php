<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // Change Recommendation Drafts (Component 3)
        Schema::create('atlas_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('title', 255);
            $table->text('justification');
            $table->decimal('impact_score', 5, 2)->nullable();
            $table->decimal('expected_revenue_gain', 12, 2)->default(0);
            $table->decimal('estimated_time_saved', 10, 2)->default(0);
            $table->jsonb('proposed_dag');
            $table->jsonb('simulation_result')->default('{}');
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->enum('status', ['pending_review', 'accepted', 'rejected', 'archived'])->default('pending_review');
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        // Playbook Sandbox Simulation (Component 5)
        Schema::create('playbook_simulations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recommendation_id');
            $table->decimal('success_rate', 5, 2);
            $table->decimal('failure_rate', 5, 2);
            $table->decimal('projected_gain', 12, 2);
            $table->jsonb('edge_case_logs')->default('[]');
            $table->timestamps();
            $table->foreign('recommendation_id')->references('id')->on('atlas_recommendations')->onDelete('cascade');
        });

        // Workspace Autonomy Settings (Component 6)
        Schema::create('workspace_autonomy_settings', function (Blueprint $table) {
            $table->uuid('workspace_id')->primary();
            $table->integer('autonomy_level')->default(1);
            $table->decimal('auto_execute_threshold', 3, 2)->default(0.85);
            $table->decimal('rollback_threshold', 3, 2)->default(0.05);
            $table->timestamps();
        });

        // Atlas Memory Events (Component 7)
        Schema::create('atlas_memory_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('event_type', 255);
            $table->string('outcome', 100);
            $table->decimal('confidence', 3, 2);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->index(['workspace_id', 'event_type']);
        });

        // Revenue Signals (Component 12)
        Schema::create('revenue_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('signal_type', 100);
            $table->decimal('value', 15, 4);
            $table->string('source_origin', 255);
            $table->timestamps();
            $table->index(['workspace_id', 'signal_type', 'created_at']);
        });

        // Policy Updates for RL (internal)
        Schema::create('atlas_policy_updates', function (Blueprint $table) {
            $table->uuid('recommendation_id')->primary();
            $table->decimal('reward_score', 10, 4)->default(0);
            $table->string('metric_applied');
            $table->timestamp('processed_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('atlas_policy_updates');
        Schema::dropIfExists('revenue_signals');
        Schema::dropIfExists('atlas_memory_events');
        Schema::dropIfExists('workspace_autonomy_settings');
        Schema::dropIfExists('playbook_simulations');
        Schema::dropIfExists('atlas_recommendations');
    }
};
