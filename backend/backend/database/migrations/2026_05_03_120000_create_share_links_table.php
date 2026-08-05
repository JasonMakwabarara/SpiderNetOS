<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('resource_type', 24)->index();
            $table->uuid('resource_id')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['tenant_id', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};
