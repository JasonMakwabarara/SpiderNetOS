<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('portfolio_id')->index();
            $table->uuid('tenant_id')->index();
            $table->string('order_id')->nullable();
            $table->string('asset_type', 20)->index();
            $table->string('symbol');
            $table->string('side', 10)->index();
            $table->decimal('quantity', 19, 8);
            $table->decimal('price', 19, 4);
            $table->decimal('commission', 19, 4)->default(0);
            $table->decimal('total_value', 19, 4);
            $table->string('status', 20)->default('pending')->index();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
