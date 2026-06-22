<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_business_profiles', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->string('industry', 64)->nullable();
            $table->string('employee_count_band', 24)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('region', 64)->nullable();
            $table->boolean('data_handles_pii')->default(false);
            $table->boolean('issues_invoices')->default(false);
            $table->boolean('hires_contractors')->default(false);
            $table->text('biggest_time_drain')->nullable();
            $table->json('pain_points')->nullable();
            $table->json('learned_signals')->nullable();
            $table->unsignedTinyInteger('discovery_complete_pct')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_business_profiles');
    }
};
