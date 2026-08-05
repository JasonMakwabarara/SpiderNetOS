<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('account_number', 50)->nullable();
            $table->string('type', 20)->index();
            $table->string('category', 50)->nullable();
            $table->string('currency', 10)->default('USD');
            $table->string('status', 20)->default('active')->index();
            $table->string('external_id')->nullable();
            $table->string('external_provider')->nullable();
            $table->decimal('balance', 19, 4)->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
