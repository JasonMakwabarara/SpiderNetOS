<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agent mesh registrations table for persistent agent information
        Schema::create('agent_mesh_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('agent_id', 128)->index();
            $table->json('capabilities'); // Array of capability strings
            $table->json('metadata')->nullable(); // Additional agent metadata
            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'agent_id']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'last_seen_at']);
        });

        // Agent mesh messages table for persistent message storage
        Schema::create('agent_mesh_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('message_id', 128)->index();
            $table->string('from_agent_id', 128)->index();
            $table->string('to_agent_id', 128)->index();
            $table->string('message_type', 64)->index();
            $table->json('payload');
            $table->string('correlation_id', 128)->nullable()->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->timestamp('sent_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 32)->default('sent')->index(); // 'sent', 'delivered', 'processed', 'failed'
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'from_agent_id', 'sent_at']);
            $table->index(['tenant_id', 'to_agent_id', 'status']);
            $table->index(['tenant_id', 'correlation_id']);
        });

        // Agent collaborations table for tracking multi-agent work
        Schema::create('agent_mesh_collaborations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('collaboration_id', 128)->index();
            $table->string('initiator_agent_id', 128)->index();
            $table->string('collaboration_type', 64)->index();
            $table->json('requirements');
            $table->json('participants'); // Array of agent IDs
            $table->string('status', 32)->default('negotiating')->index(); // 'negotiating', 'active', 'completed', 'failed'
            $table->json('responses')->nullable(); // Participant responses
            $table->json('results')->nullable(); // Final collaboration results
            $table->timestamp('created_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'collaboration_type']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Agent performance metrics table
        Schema::create('agent_mesh_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('agent_id', 128)->index();
            $table->string('metric_type', 64)->index(); // 'response_time', 'success_rate', 'collaboration_score'
            $table->float('metric_value');
            $table->timestamp('measured_at');
            $table->json('context')->nullable(); // Additional measurement context
            $table->timestamps();

            $table->index(['tenant_id', 'agent_id', 'metric_type', 'measured_at']);
            $table->index(['tenant_id', 'metric_type', 'measured_at']);
        });

        // Agent capability usage tracking
        Schema::create('agent_mesh_capability_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('agent_id', 128)->index();
            $table->string('capability', 128)->index();
            $table->integer('usage_count')->default(0);
            $table->float('avg_response_time')->default(0.0);
            $table->float('success_rate')->default(0.0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'agent_id', 'capability']);
            $table->index(['tenant_id', 'capability', 'usage_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_mesh_capability_usage');
        Schema::dropIfExists('agent_mesh_metrics');
        Schema::dropIfExists('agent_mesh_collaborations');
        Schema::dropIfExists('agent_mesh_messages');
        Schema::dropIfExists('agent_mesh_registrations');
    }
};