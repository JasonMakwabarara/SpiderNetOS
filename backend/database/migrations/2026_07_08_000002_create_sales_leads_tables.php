<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp_number', 32)->nullable();
            $table->string('source', 64)->default('manual');
            $table->string('stage', 32)->default('captured');
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('owner_agent_slug', 64)->nullable();
            // Plain string defaults (not DB::raw(...::jsonb)) so this migration
            // runs unchanged on the sqlite :memory: connection used by the
            // Unit/Feature/CiFast test suites (see backend/phpunit.xml).
            $table->jsonb('consent')->default('{"email_opt_in":true,"whatsapp_opt_in":true,"opted_out_at":null}');
            $table->jsonb('custom')->default('{}');
            $table->timestampTz('last_contacted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stage']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'whatsapp_number']);
        });

        // Partial unique index: only enforce one lead per (tenant, email) when
        // email is present — leads captured without an email (WhatsApp-only,
        // phone-only) must not collide on a shared NULL.
        DB::statement('
            CREATE UNIQUE INDEX leads_tenant_email_unique
              ON leads (tenant_id, email)
              WHERE email IS NOT NULL
        ');

        Schema::create('deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('lead_id')->index();
            $table->bigInteger('value_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('stage', 32)->default('proposal');
            $table->timestampTz('expected_close_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->index(['tenant_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
        Schema::dropIfExists('leads');
    }
};
