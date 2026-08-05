<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ai_training_bundles
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

        // ai_training_examples
        Schema::create('ai_training_examples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bundle_id')->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->text('user_prompt');
            $table->text('assistant_completion');
            $table->json('messages')->nullable();
            $table->integer('quality_score')->default(0);
            $table->json('metadata')->nullable();
            $table->string('label', 32)->nullable()->index();
            $table->boolean('included_in_sft')->default(false);
            $table->timestamps();

            $table->index(['bundle_id', 'quality_score']);
            $table->index(['tenant_id', 'quality_score']);
            $table->index(['bundle_id', 'included_in_sft']);
        });

        // ai_preference_pairs
        Schema::create('ai_preference_pairs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bundle_id')->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->text('prompt');
            $table->text('chosen');
            $table->text('rejected');
            $table->integer('quality_score')->default(0);
            $table->string('rejected_type', 64)->default('unknown');
            $table->json('metadata')->nullable();
            $table->boolean('included_in_training')->default(false);
            $table->timestamps();

            $table->index(['bundle_id', 'quality_score']);
            $table->index(['bundle_id', 'rejected_type']);
            $table->index(['tenant_id', 'quality_score']);
        });

        // ai_quality_reports
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
        Schema::dropIfExists('ai_preference_pairs');
        Schema::dropIfExists('ai_training_examples');
        Schema::dropIfExists('ai_training_bundles');
    }
};
