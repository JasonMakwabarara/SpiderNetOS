<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_checkpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->string('step', 128);
            $table->string('status', 16)->default('pending');
            $table->jsonb('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['execution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_checkpoints');
    }
};
