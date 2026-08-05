<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('transaction_number', 50)->unique();
            $table->string('type', 30)->index();
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10)->default('USD');
            $table->string('status', 20)->default('pending')->index();
            $table->string('payment_method')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('source_account_id')->nullable();
            $table->string('destination_account_id')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_email')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key')->unique()->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
