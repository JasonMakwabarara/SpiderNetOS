<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connection health for integrations: whether the stored credentials actually
 * work, when that was last proven, and the last failure reason. Surfaced in the
 * cockpit Connectors UI so a silently-broken connector is visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->string('status', 16)->default('pending'); // pending | connected | error
            $table->timestampTz('last_verified_at')->nullable();
            $table->string('last_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_verified_at', 'last_error']);
        });
    }
};
