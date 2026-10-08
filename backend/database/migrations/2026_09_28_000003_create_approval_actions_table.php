<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The action a single-stage decision owes, written in the decision's own
 * transaction. Before this, the decision committed and its resource hook ran
 * afterwards with nothing recording that it was owed: a process that died in
 * between, or a hook that threw, left the approval decided and its effect
 * never applied, and a retry of the decision got 409. The row is the record
 * that something is still owed; ApprovalActions runs it and the recovery
 * command (approvals:recover-actions) drains what is left.
 *
 * status: pending | running | done | failed | uncertain (varchar 16 — every
 * value fits; Postgres enforces the length, SQLite does not).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('approval_id')->unique();
            $table->string('resource_type', 64);
            $table->string('resource_id', 64);
            $table->boolean('granted');
            $table->text('response')->nullable();
            $table->json('decision');
            $table->string('delivery', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
    }
};
