<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('category', 50)->nullable();
            $table->decimal('amount', 19, 4);
            $table->decimal('spent', 19, 4)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('period', 20)->default('monthly')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('alert_threshold', 5, 2)->default(80);
            $table->string('status', 20)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
