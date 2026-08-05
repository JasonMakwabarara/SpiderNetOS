<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Stage 1c — merchant -> category memory table.
|
| Every user confirmation of a spend document (or expense category pick)
| upserts a row here via SpendAutomationProjection. The category suggestion
| cascade consults this table first; confidence grows with confirm_count.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_category_map', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('merchant_normalized')->index();
            $table->string('category', 50);
            $table->string('chart_account_code', 20)->nullable();
            $table->integer('confirm_count')->default(1);
            $table->timestamp('last_confirmed_at')->nullable();
            $table->string('source', 20)->default('user_confirm');
            $table->timestamps();

            $table->unique(['tenant_id', 'merchant_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_category_map');
    }
};
