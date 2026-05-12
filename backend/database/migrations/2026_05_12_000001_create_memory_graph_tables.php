<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Memory nodes table for storing knowledge with vector embeddings
        Schema::create('memory_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->json('content'); // Flexible content storage
            $table->json('metadata')->nullable(); // Additional metadata
            $table->json('vector_embedding'); // Vector for semantic search
            $table->float('importance_score')->default(1.0)->index();
            $table->integer('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['tenant_id', 'importance_score']);
            $table->index(['tenant_id', 'is_active', 'created_at']);
            $table->index(['tenant_id', 'access_count']);
        });

        // Memory relationships table for knowledge graph
        Schema::create('memory_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_node_id')->index();
            $table->uuid('target_node_id')->index();
            $table->string('relationship_type', 64)->index();
            $table->float('strength')->default(1.0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('source_node_id')->references('id')->on('memory_nodes')->onDelete('cascade');
            $table->foreign('target_node_id')->references('id')->on('memory_nodes')->onDelete('cascade');

            // Indexes for graph traversal
            $table->index(['source_node_id', 'relationship_type']);
            $table->index(['target_node_id', 'relationship_type']);
            $table->index(['relationship_type', 'strength']);
        });

        // Memory patterns table for discovered patterns
        Schema::create('memory_patterns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('pattern_type', 64)->index();
            $table->json('pattern_data');
            $table->float('confidence_score')->default(0.0);
            $table->integer('occurrence_count')->default(1);
            $table->timestamp('last_observed_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'pattern_type', 'confidence_score']);
        });

        // Memory consolidation log
        Schema::create('memory_consolidation_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->timestamp('consolidation_date');
            $table->integer('nodes_consolidated')->default(0);
            $table->integer('nodes_compressed')->default(0);
            $table->integer('relationships_created')->default(0);
            $table->float('avg_importance_change')->default(0.0);
            $table->json('consolidation_details')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'consolidation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_consolidation_log');
        Schema::dropIfExists('memory_patterns');
        Schema::dropIfExists('memory_relationships');
        Schema::dropIfExists('memory_nodes');
    }
};