<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('dag_id')->index();
            $table->uuid('tenant_id')->index();
            $table->uuid('flow_execution_id')->index();
            $table->jsonb('node_states');
            $table->jsonb('execution_log');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'dag_id', 'started_at']);
        });
        
        Schema::create('anomalies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('anomaly_type', 64)->index();
            $table->string('severity', 16)->index();
            $table->text('description');
            $table->jsonb('context');
            $table->jsonb('signals');
            $table->string('status', 16)->default('open');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['anomaly_type', 'detected_at']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('anomalies');
        Schema::dropIfExists('traces');
    }
};
