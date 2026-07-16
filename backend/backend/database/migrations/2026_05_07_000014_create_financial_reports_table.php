<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('type', 30)->index();
            $table->string('title');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('format', 20)->default('json')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->json('data')->nullable();
            $table->string('pdf_path')->nullable();
            $table->uuid('generated_by')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_reports');
    }
};
