<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RL experiences table for storing learning data
        Schema::create('rl_experiences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('action_id', 128)->index();
            $table->string('action_type', 64)->index();
            $table->float('reward')->index();
            $table->json('context')->nullable(); // State/context information
            $table->json('outcome')->nullable(); // Action results
            $table->json('state_vector'); // Vector representation of state
            $table->boolean('processed')->default(false)->index();
            $table->timestamp('timestamp');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['tenant_id', 'action_type', 'reward']);
            $table->index(['tenant_id', 'processed', 'timestamp']);
            $table->index(['tenant_id', 'action_id']);
        });

        // Action statistics table for tracking performance
        Schema::create('rl_action_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('action_type', 64)->index();
            $table->string('action_id', 128)->index();
            $table->integer('total_attempts')->default(0);
            $table->integer('success_count')->default(0);
            $table->float('total_reward')->default(0.0);
            $table->float('avg_reward')->default(0.0);
            $table->float('success_rate')->default(0.0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'action_type', 'action_id']);
            $table->index(['tenant_id', 'action_type', 'success_rate']);
            $table->index(['tenant_id', 'last_used_at']);
        });

        // Learning policies table for storing trained policies
        Schema::create('rl_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('policy_type', 64)->index(); // 'agent_selection', 'workflow_optimization', etc.
            $table->string('policy_version', 32);
            $table->json('policy_data'); // Serialized policy (Q-table, neural network weights, etc.)
            $table->float('performance_score')->default(0.0);
            $table->integer('training_samples')->default(0);
            $table->timestamp('trained_at');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'policy_type', 'is_active']);
            $table->index(['tenant_id', 'performance_score']);
        });

        // Learning insights table for discovered patterns
        Schema::create('rl_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('insight_type', 64)->index(); // 'pattern', 'correlation', 'optimization'
            $table->json('insight_data');
            $table->float('confidence_score')->default(0.0);
            $table->float('impact_score')->default(0.0); // Expected improvement
            $table->integer('observation_count')->default(1);
            $table->timestamp('last_observed_at');
            $table->boolean('implemented')->default(false)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'insight_type', 'confidence_score']);
            $table->index(['tenant_id', 'implemented', 'impact_score']);
        });

        // Learning experiments table for A/B testing
        Schema::create('rl_experiments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('experiment_name', 128);
            $table->text('description')->nullable();
            $table->string('experiment_type', 64)->index(); // 'policy_comparison', 'parameter_tuning'
            $table->json('variants'); // Different policy variants to test
            $table->json('metrics'); // What to measure
            $table->timestamp('start_date');
            $table->timestamp('end_date')->nullable();
            $table->string('status', 32)->default('active')->index(); // 'active', 'completed', 'stopped'
            $table->json('results')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'experiment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rl_experiments');
        Schema::dropIfExists('rl_insights');
        Schema::dropIfExists('rl_policies');
        Schema::dropIfExists('rl_action_stats');
        Schema::dropIfExists('rl_experiences');
    }
};