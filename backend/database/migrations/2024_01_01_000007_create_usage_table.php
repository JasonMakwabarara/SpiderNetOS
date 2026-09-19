<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('agent_id')->nullable()->index();
            $table->string('resource_type', 32)->index();
            $table->string('model', 64)->nullable();
            $table->integer('tokens_input')->default(0);
            $table->integer('tokens_output')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->integer('duration_ms')->nullable();
            $table->string('status', 16)->default('success');
            $table->timestamp('recorded_at')->index();
            $table->jsonb('metadata')->nullable();
        });

        Schema::create('usage_daily_aggregates', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->date('date')->index();
            $table->string('resource_type', 32);
            $table->integer('total_calls')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('total_cost', 12, 6)->default(0);
            $table->decimal('cost_ceiling', 12, 6)->default(0);
            $table->timestamp('calculated_at');

            $table->unique(['tenant_id', 'date', 'resource_type']);
        });

        Schema::create('cost_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->decimal('daily_limit', 12, 6)->default(10.00);
            $table->decimal('monthly_limit', 12, 6)->default(100.00);
            $table->decimal('alert_threshold', 5, 2)->default(0.80);
            $table->string('action_at_limit', 16)->default('block');
            $table->jsonb('notifications')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_budgets');
        Schema::dropIfExists('usage_daily_aggregates');
        Schema::dropIfExists('usage_records');
    }
};
