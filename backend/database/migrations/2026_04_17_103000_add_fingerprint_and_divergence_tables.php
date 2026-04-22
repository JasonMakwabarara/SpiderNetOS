<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_executions', function (Blueprint $table) {
            $table->string('fingerprint', 64)->nullable()->index();
        });

        Schema::create('execution_fingerprint_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('fingerprint', 64)->index();
            $table->uuid('source_execution_id')->nullable()->index();
            $table->jsonb('result_payload');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('valid_until')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_id', 'fingerprint']);
        });

        Schema::create('replay_divergence_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('execution_id')->index();
            $table->string('status', 16)->default('clean')->index();
            $table->jsonb('replay_state');
            $table->jsonb('live_state');
            $table->jsonb('divergences');
            $table->integer('divergence_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replay_divergence_reports');
        Schema::dropIfExists('execution_fingerprint_cache');

        Schema::table('flow_executions', function (Blueprint $table) {
            $table->dropColumn('fingerprint');
        });
    }
};
