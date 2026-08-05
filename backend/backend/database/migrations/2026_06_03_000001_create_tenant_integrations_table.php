<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D — Tenant Integrations
 *
 * Stores which third-party integrations (calendar, CRM) a tenant has configured.
 * Credentials are stored encrypted in tenant_secrets; this table holds a reference key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_integrations', function (Blueprint $table) {
            $table->id();

            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Provider identifier: google_calendar, cal_com, hubspot, salesforce, ...
            $table->string('provider', 50);

            // Integration type: calendar, crm, email, ...
            $table->string('type', 30);

            // Reference to the encrypted credentials in tenant_secrets table
            // This is the 'name' key used in tenant_secrets
            $table->string('credentials_ref', 200)->nullable();

            $table->boolean('is_active')->default(true);

            // Provider-specific metadata (e.g. calendar_id, event_type_id)
            $table->jsonb('config')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'type']);
            $table->index(['tenant_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_integrations');
    }
};
