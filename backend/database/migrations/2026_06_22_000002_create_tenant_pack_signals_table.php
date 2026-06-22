<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_pack_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('pack_id', 64)->nullable()->index();
            $table->string('signal_type', 48)->index();
            $table->json('context')->nullable();
            $table->smallInteger('weight')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'pack_id', 'signal_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pack_signals');
    }
};
