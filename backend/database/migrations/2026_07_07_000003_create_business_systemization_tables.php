<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Business Systemization pack — systems map, ownership, SOPs.
|
| The model follows the systemization method: every business function
| (marketing, sales, operations, finance, recruitment, management) contains
| systems; systems contain processes; every process has exactly ONE owner
| (a person or an agent); founder-owned processes queue up for delegation
| smallest-first (the snowball); each process can carry versioned SOPs.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_systems', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('function', 32)->index(); // marketing|sales|operations|finance|recruitment|management
            $table->string('name', 120);
            $table->text('goal')->nullable();
            $table->uuid('owner_user_id')->nullable();
            $table->uuid('owner_agent_id')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'function', 'name']);
        });

        Schema::create('business_processes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('system_id')->index();
            $table->string('name', 160);
            $table->text('goal')->nullable();
            // Exactly one owner: founder (default), a teammate, or an agent.
            $table->string('owner_type', 16)->default('founder'); // founder|team|agent
            $table->uuid('owner_user_id')->nullable();
            $table->uuid('owner_agent_id')->nullable();
            // 1 = smallest — the snowball delegates smallest first.
            $table->unsignedTinyInteger('effort_size')->default(3);
            $table->string('status', 24)->default('founder_owned'); // founder_owned|delegated|automated
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'effort_size']);
        });

        Schema::create('sops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('process_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('title', 160);
            $table->text('purpose')->nullable();
            $table->text('trigger')->nullable();
            $table->json('tools')->nullable();
            $table->json('steps');
            $table->json('quality_criteria')->nullable();
            $table->string('status', 16)->default('draft'); // draft|published|archived
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['process_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sops');
        Schema::dropIfExists('business_processes');
        Schema::dropIfExists('business_systems');
    }
};
