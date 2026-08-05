<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('account_id')->index();
            $table->uuid('chart_account_id')->nullable();
            $table->string('transaction_id')->index();
            $table->string('entry_type', 20)->index();
            $table->enum('side', ['debit', 'credit']);
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10)->default('USD');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->string('event_log_id')->nullable()->index();
            $table->timestamp('posted_at')->index();
            $table->timestamps();

            $table->index(['tenant_id', 'transaction_id']);
            $table->index(['tenant_id', 'posted_at']);
            $table->index(['tenant_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
