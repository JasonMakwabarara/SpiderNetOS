<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_registrations', function (Blueprint $table) {
            $table->string('id', 40)->primary(); // ent_xxx
            $table->string('org_name');
            $table->string('contact_email');
            $table->string('contact_name')->nullable();
            $table->string('domain');
            $table->string('domain_token', 64);
            $table->boolean('domain_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('region', 32)->default('us-east-1');
            $table->string('status', 24)->default('draft'); // draft|active
            $table->string('scim_token_hash', 64)->nullable();
            $table->timestamp('scim_expires_at')->nullable();
            $table->timestamps();

            $table->index(['domain', 'status']);
        });

        Schema::create('aios_bundle_requests', function (Blueprint $table) {
            $table->string('id', 40)->primary(); // bdl_xxx
            $table->uuid('tenant_id')->index();
            $table->string('enterprise_id', 40)->nullable();
            $table->string('target', 40);
            $table->json('components');
            $table->string('status', 24)->default('queued'); // queued|building|ready|failed
            $table->string('sha256', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('aios_deployments', function (Blueprint $table) {
            $table->string('id', 40)->primary(); // dep_xxx
            $table->string('bundle_id', 40)->index();
            $table->uuid('tenant_id')->index();
            $table->string('status', 24)->default('queued'); // queued|deploying|complete|failed
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aios_deployments');
        Schema::dropIfExists('aios_bundle_requests');
        Schema::dropIfExists('enterprise_registrations');
    }
};
