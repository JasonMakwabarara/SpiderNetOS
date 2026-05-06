<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_training_bundles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('bundle_name', 128)->index();
            $table->string('source_path', 512)->nullable();
            $table->string('source_type', 32)->default('cursor_markdown');
            $table->json('metadata')->nullable();
            $table->integer('total_turns')->default(0);
            $table->integer('sft_count')->default(0);
            $table->integer('preference_count')->default(0);
            $table->float('mean_quality_score')->default(0);
            $table->string('status', 32)->default('pending');
            $table->timestamp('gated_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['bundle_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_bundles');
    }
};
