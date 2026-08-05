<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_examples');
    }
};
