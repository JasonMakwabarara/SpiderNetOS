<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role-split frontend support:
     *   - expand role enum (viewer, member, admin, super_admin)
     *   - track step-up MFA
     *   - persist per-user capability overrides
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Step-up MFA timestamp for sensitive ops
            $table->timestamp('step_up_at')->nullable()->after('last_login_at');

            // Per-user capability grants (string array, jsonb)
            $table->jsonb('capabilities')->nullable()->after('preferences');

            // Platform-scope flag (super admin only)
            $table->boolean('is_platform_admin')->default(false)->after('role');

            // Invitation tracking
            $table->uuid('invited_by')->nullable()->after('is_platform_admin');
            $table->timestamp('invited_at')->nullable()->after('invited_by');
            $table->timestamp('accepted_at')->nullable()->after('invited_at');

            $table->index('is_platform_admin');
        });

        // Audit log table for admin actions (separate from event_log for fast querying)
        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('actor_id')->index();
            $table->string('actor_email', 255);
            $table->string('action', 64)->index(); // user.invited, user.deleted, flag.toggled, impersonate.started
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 255)->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['tenant_id', 'created_at']);
        });

        // Impersonation sessions
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id')->index();
            $table->uuid('target_user_id')->index();
            $table->uuid('target_tenant_id')->index();
            $table->text('reason');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->jsonb('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('admin_audit_log');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_platform_admin']);
            $table->dropColumn([
                'step_up_at',
                'capabilities',
                'is_platform_admin',
                'invited_by',
                'invited_at',
                'accepted_at',
            ]);
        });
    }
};
