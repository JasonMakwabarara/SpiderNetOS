<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Accountable ownership wiring: a process can carry a compiled Flow
| (its runbook), run on a schedule, and record every run's outcome.
| Two consecutive failures escalate to a human via the ApprovalEngine.
| SOPs gain notes — escalation answers are captured as revisions.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_processes', function (Blueprint $table) {
            $table->uuid('flow_id')->nullable()->index();
            $table->string('schedule_cron', 64)->nullable();
            $table->uuid('last_execution_id')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status', 16)->nullable(); // passed|failed|paused|running
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->boolean('needs_attention')->default(false);
            $table->uuid('escalation_approval_id')->nullable();
        });

        Schema::table('sops', function (Blueprint $table) {
            $table->json('notes')->nullable(); // escalation answers, revision context
        });
    }

    public function down(): void
    {
        Schema::table('business_processes', function (Blueprint $table) {
            $table->dropColumn([
                'flow_id', 'schedule_cron', 'last_execution_id', 'last_run_at',
                'last_run_status', 'consecutive_failures', 'needs_attention',
                'escalation_approval_id',
            ]);
        });

        Schema::table('sops', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
