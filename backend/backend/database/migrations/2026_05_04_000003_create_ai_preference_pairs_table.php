<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_preference_pairs');
    }
};
