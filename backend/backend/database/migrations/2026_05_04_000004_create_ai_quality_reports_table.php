<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_quality_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bundle_id')->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->boolean('gate_passed')->default(false);
            $table->json('gate_results')->nullable();
            $table->json('metrics')->nullable();
            $table->json('distributions')->nullable();
            $table->integer('sft_rows')->default(0);
            $table->integer('preference_rows')->default(0);
            $table->float('sft_mean_quality')->default(0);
            $table->float('preference_mean_quality')->default(0);
            $table->float('distinct_preference_ratio')->default(0);
            $table->json('rejected_type_distribution')->nullable();
            $table->timestamps();

            $table->index(['bundle_id', 'gate_passed']);
            $table->index(['tenant_id', 'gate_passed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_quality_reports');
    }
};
