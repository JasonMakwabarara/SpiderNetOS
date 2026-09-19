<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->jsonb('dag');
            $table->jsonb('triggers');
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('flow_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('flow_id')->index();
            $table->uuid('tenant_id')->index();
            $table->string('status', 16)->default('pending');
            $table->jsonb('context');
            $table->jsonb('results')->nullable();
            $table->jsonb('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['flow_id', 'status']);
            $table->index(['tenant_id', 'started_at']);
        });

        Schema::create('dag_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('flow_id')->index();
            $table->string('node_type', 32);
            $table->string('agent_id')->nullable()->index();
            $table->jsonb('config');
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->timestamps();
        });

        Schema::create('dag_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('flow_id')->index();
            $table->uuid('source_node_id')->index();
            $table->uuid('target_node_id')->index();
            $table->string('condition')->nullable();
            $table->timestamps();

            $table->index(['flow_id', 'source_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dag_edges');
        Schema::dropIfExists('dag_nodes');
        Schema::dropIfExists('flow_executions');
        Schema::dropIfExists('flows');
    }
};
