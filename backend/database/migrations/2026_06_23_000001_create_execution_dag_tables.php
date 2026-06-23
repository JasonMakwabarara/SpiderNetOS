<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_dag_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->uuid('tenant_id')->index();
            $table->string('node_id', 64);
            $table->string('node_type', 32);
            $table->string('label')->nullable();
            $table->string('agent_id')->nullable();
            $table->jsonb('config')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->jsonb('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['execution_id', 'node_id']);
            $table->index(['execution_id', 'status']);
        });

        Schema::create('execution_dag_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->string('from_node_id', 64);
            $table->string('to_node_id', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['execution_id', 'to_node_id']);
        });

        Schema::table('flows', function (Blueprint $table) {
            if (! Schema::hasColumn('flows', 'schedule_cron')) {
                $table->string('schedule_cron', 64)->nullable()->after('status');
            }
            if (! Schema::hasColumn('flows', 'schedule_timezone')) {
                $table->string('schedule_timezone', 64)->nullable()->default('UTC')->after('schedule_cron');
            }
            if (! Schema::hasColumn('flows', 'last_scheduled_at')) {
                $table->timestamp('last_scheduled_at')->nullable()->after('schedule_timezone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            foreach (['schedule_cron', 'schedule_timezone', 'last_scheduled_at'] as $col) {
                if (Schema::hasColumn('flows', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('execution_dag_edges');
        Schema::dropIfExists('execution_dag_nodes');
    }
};
