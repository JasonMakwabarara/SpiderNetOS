<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('strategy', 50)->nullable();
            $table->string('risk_level', 20)->default('medium')->index();
            $table->string('currency', 10)->default('USD');
            $table->decimal('total_value', 19, 4)->default(0);
            $table->decimal('cash_balance', 19, 4)->default(0);
            $table->decimal('realized_pnl', 19, 4)->default(0);
            $table->decimal('unrealized_pnl', 19, 4)->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('portfolio_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('portfolio_id')->index();
            $table->uuid('tenant_id')->index();
            $table->string('asset_type', 20)->index();
            $table->string('symbol');
            $table->string('name')->nullable();
            $table->decimal('quantity', 19, 8);
            $table->decimal('avg_cost', 19, 4);
            $table->decimal('current_price', 19, 4)->nullable();
            $table->decimal('market_value', 19, 4)->nullable();
            $table->decimal('unrealized_pnl', 19, 4)->default(0);
            $table->decimal('realized_pnl', 19, 4)->default(0);
            $table->decimal('weight', 5, 2)->nullable();
            $table->timestamp('last_price_update')->nullable();
            $table->timestamps();

            $table->foreign('portfolio_id')->references('id')->on('portfolios')->onDelete('cascade');
            $table->unique(['portfolio_id', 'asset_type', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_positions');
        Schema::dropIfExists('portfolios');
    }
};
