<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('plan_id', 50);
            $table->string('plan_name');
            $table->string('provider', 30)->default('stripe');
            $table->string('provider_subscription_id')->unique();
            $table->string('status', 20)->default('active')->index();
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10)->default('USD');
            $table->string('interval', 20)->default('monthly');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('subscription_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id')->index();
            $table->uuid('tenant_id')->index();
            $table->string('metric_name');
            $table->decimal('quantity', 19, 4);
            $table->string('period_start');
            $table->string('period_end');
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usage');
        Schema::dropIfExists('subscriptions');
    }
};
