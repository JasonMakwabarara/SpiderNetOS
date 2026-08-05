<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('invoice_id')->nullable()->index();
            $table->uuid('transaction_id')->nullable()->index();
            $table->string('payment_number', 50)->unique();
            $table->string('type', 20)->default('received')->index();
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10)->default('USD');
            $table->string('method', 30)->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
