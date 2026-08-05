<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Multi-stage approval chains.
|
| approval_policies      — per-tenant routing rules (which resource/action
|                          needs approval, matched by priority + conditions).
|                          Also closes the long-standing gap where
|                          ApprovalEngine::isApprovalRequired() queried this
|                          table but no migration ever created it.
| approval_policy_steps  — ordered approver template per policy (role or
|                          specific user, expiry + escalation).
| approval_steps         — runtime step instances materialized per approval.
| approvals              — gains policy_id + current_step; current_step NULL
|                          means legacy single-stage record (backward compat
|                          discriminator used by ApprovalEngine).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('resource_type', 32);
            $table->string('action', 32)->default('submit');
            $table->string('name', 120);
            $table->boolean('enabled')->default(true);
            $table->integer('priority')->default(0);
            // {"min_amount": "5000.00", "max_amount": null, "currency": "USD", "categories": [...]}
            $table->json('conditions')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'resource_type', 'action', 'enabled']);
        });

        Schema::create('approval_policy_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_policy_id')
                ->constrained('approval_policies')
                ->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->string('approver_type', 10)->default('role'); // role|user
            $table->string('approver_role', 20)->nullable();
            $table->uuid('approver_id')->nullable();
            $table->unsignedInteger('expires_after_hours')->nullable();
            $table->string('escalate_to_role', 20)->nullable();
            $table->timestamps();

            $table->unique(['approval_policy_id', 'step_order']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_id')
                ->constrained('approvals')
                ->cascadeOnDelete();
            $table->uuid('tenant_id')->index();
            $table->unsignedInteger('step_order');
            $table->string('approver_type', 10)->default('role');
            $table->string('approver_role', 20)->nullable();
            $table->uuid('approver_id')->nullable();
            $table->uuid('delegated_to')->nullable();
            // queued|pending|approved|rejected|expired|skipped
            $table->string('status', 16)->default('queued');
            $table->uuid('acted_by')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['approval_id', 'step_order']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->uuid('policy_id')->nullable();
            $table->unsignedInteger('current_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['policy_id', 'current_step']);
        });
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_policy_steps');
        Schema::dropIfExists('approval_policies');
    }
};
