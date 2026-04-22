<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add onboarding fields to tenants table
        Schema::table('tenants', function (Blueprint $table) {
            $table->jsonb('onboarding')->nullable();
            $table->timestampTz('onboarding_completed_at')->nullable();
            $table->string('automation_level', 20)
                ->default('assisted')
                ->check("automation_level in ('manual', 'assisted', 'autonomous')");
        });

        // Add onboarding completion tracking to users table
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('onboarding_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['onboarding', 'onboarding_completed_at', 'automation_level']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
