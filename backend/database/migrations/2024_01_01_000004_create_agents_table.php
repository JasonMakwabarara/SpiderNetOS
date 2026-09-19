<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->string('type', 32)->index();
            $table->string('status', 16)->default('inactive');
            $table->jsonb('capabilities');
            $table->jsonb('config')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status', 'type']);
        });

        Schema::create('agent_delegations', function (Blueprint $table) {
            $table->id();
            $table->uuid('agent_id')->index();
            $table->uuid('delegate_id')->index();
            $table->string('permission', 64);
            $table->jsonb('conditions')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'delegate_id']);
            $table->index(['agent_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_delegations');
        Schema::dropIfExists('agents');
    }
};
