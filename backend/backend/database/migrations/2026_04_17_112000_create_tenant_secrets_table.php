<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_secrets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('key_name', 64)->index();
            $table->text('secret_value');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'key_name', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_secrets');
    }
};
