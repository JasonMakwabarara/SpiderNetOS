<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('agent_id')->nullable()->index();
            $table->string('node_type', 32)->index();
            $table->text('content');
            $table->vector('embedding', 384)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->float('recency_score')->default(0);
            $table->float('importance_score')->default(0);
            $table->integer('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'node_type']);
            $table->index(['tenant_id', 'agent_id', 'node_type']);
        });
        
        Schema::create('memory_edges', function (Blueprint $table) {
            $table->id();
            $table->uuid('source_id')->index();
            $table->uuid('target_id')->index();
            $table->string('relation_type', 64);
            $table->float('weight')->default(1.0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->unique(['source_id', 'target_id', 'relation_type']);
        });
        
        DB::statement('CREATE INDEX ON memory_nodes USING ivfflat (embedding vector_cosine_ops)');
    }
    
    public function down(): void
    {
        Schema::dropIfExists('memory_edges');
        Schema::dropIfExists('memory_nodes');
    }
};
