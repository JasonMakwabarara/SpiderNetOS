<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('pack_id')->index();
            $table->string('version');
            $table->string('vertical')->nullable()->index();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->json('manifest')->nullable();
            $table->string('status', 16)->default('installed')->index();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'pack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_packs');
    }
};
