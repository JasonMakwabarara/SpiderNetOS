<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Daily Brief Generation (06:00 daily) — composes Needs-You Today per tenant (FounderBriefService)
Schedule::job(new \App\Jobs\GenerateDailyBriefJob)->dailyAt('06:00');

// Needs-You Today delivery (plan D8 #3): hourly sweep that recomposes and
// pushes brief_ready at 06:55–07:05 in each tenant's own timezone, then
// flushes due notification bundles (D8 #4) — same pattern as OutreachDailyDigestJob.
Schedule::job(new \App\Jobs\FounderBriefJob)->hourly()->withoutOverlapping();

// The Monday letter (plan D8 #11) and, as its second half, the C-Suite
// newsletter (D8 #15): hourly sweep, each tenant taking the tick that is
// Monday 07:00 where they are. Gated per tenant by newsletter.csuite.
Schedule::job(new \App\Jobs\MondayLetterJob)->hourly()->withoutOverlapping();

// Every edit is a lesson (plan D8 #1): weekly distillation of artifact_revisions
// into people/user.md proposals ("you always shorten the opener → rule"), Sunday 03:00 UTC
Schedule::job(new \App\Jobs\DistilCorrectionsJob)->weeklyOn(0, '03:00')->withoutOverlapping();

// Usage Aggregation (every 5 minutes — runs only when atlas.usage_aggregates_v2=on)
Schedule::job(new \App\Jobs\AggregateUsageJob)->everyFiveMinutes();

// Atlas Prompt Evolution (weekly, Sunday 02:00 UTC — default OFF, gate checked inside job)
// Enable by setting: php artisan feature:set atlas.prompt_evolution on
Schedule::command('artisan', ['atlas:prompt-evolution'])->weekly()->sundays()->at('02:00');

// Anomaly Detection (every 5 minutes)
Schedule::job(new \App\Jobs\CheckAnomaliesJob)->everyFiveMinutes();

// Replay divergence sweep (every 10 minutes)
Schedule::job(new \App\Jobs\ReplayDivergenceSweepJob)->everyTenMinutes()->withoutOverlapping();

// Fingerprint cache prune (every 15 minutes)
Schedule::job(new \App\Jobs\PruneFingerprintCacheJob)->everyFifteenMinutes()->withoutOverlapping();

// Scheduled flow dispatch (every minute)
Schedule::job(new \App\Jobs\DispatchScheduledFlowsJob)->everyMinute()->withoutOverlapping();

// Transformation Score computation (every 5 minutes) — B6
Schedule::job(new \App\Jobs\ComputeTransformationScoreJob)->everyFiveMinutes()->withoutOverlapping();

// Sales-crm pack: nurture sequence steps (email/WhatsApp follow-ups) — every minute
Schedule::job(new \App\Jobs\ProcessSequenceStepsJob)->everyMinute()->withoutOverlapping();

// Partner outreach: DM drafts, retirements and due email steps — every minute,
// self-gated per tenant on the outreach.sending flag (see OutreachSender)
Schedule::job(new \App\Jobs\ProcessOutreachStepsJob)->everyMinute()->withoutOverlapping();

// Partner outreach: poll the tenant mailboxes for replies / bounces / STOP (every 2 min)
Schedule::job(new \App\Jobs\PollPartnerMailboxJob)->everyTwoMinutes()->withoutOverlapping();

// Partner outreach: hourly sweep that sends each tenant its digest at 08:30 local
// time and auto-pauses sending when the bounce rate crosses the threshold
Schedule::job(new \App\Jobs\OutreachDailyDigestJob)->hourly()->withoutOverlapping();

// Priestley "Activity" A — perfect repeatable week (Monday priorities, Friday check-in)
Schedule::job(new \App\Jobs\WeeklyRhythmJob('priorities'))->weeklyOn(1, '06:30');
Schedule::job(new \App\Jobs\WeeklyRhythmJob('checkin'))->weeklyOn(5, '15:00');

// Platform billing: close the previous month into invoices (fee + overage), 1st at 03:00 UTC
Schedule::command('spidernet:billing:generate-invoices')->monthlyOn(1, '03:00')->withoutOverlapping();

// DAG node watchdog — no node may stay `running` forever (every 10 minutes)
Schedule::job(new \App\Jobs\FailStaleExecutionNodesJob)->everyTenMinutes()->withoutOverlapping();

// Systemization write-back for scheduled runs (every 5 minutes)
Schedule::job(new \App\Jobs\SystemizationRunSweepJob)->everyFiveMinutes()->withoutOverlapping();

// Approval chains: escalate/expire overdue steps (every 10 minutes)
Schedule::job(new \App\Jobs\ExpireApprovalStepsJob)->everyTenMinutes()->withoutOverlapping();

// Bill pay (Stage 2): scheduled-payment sweep — record-only, notifies admins (every 15 minutes)
Schedule::job(new \App\Jobs\SweepScheduledBillPaymentsJob)->everyFifteenMinutes()->withoutOverlapping();

// Bill pay (Stage 2): bills-due-soon digest to tenant admins (daily 08:00)
Schedule::job(new \App\Jobs\NotifyBillsDueSoonJob)->dailyAt('08:00')->withoutOverlapping();

// Bill pay (Stage 2): generate draft bills from recurring templates (daily 06:15)
Schedule::job(new \App\Jobs\GenerateRecurringBillsJob)->dailyAt('06:15')->withoutOverlapping();

// Accounting (Stage 3): run due export schedules — weekly on the first run of
// the ISO week, monthly on the first run of the month (daily 04:00)
Schedule::job(new \App\Jobs\RunScheduledSpendExportsJob)->dailyAt('04:00')->withoutOverlapping();

// Accounting (Stage 3): weekly spend digest to tenant admins (Mon 07:30) —
// gated per tenant by the spend.weekly_digest flag / SPEND_WEEKLY_DIGEST env
Schedule::job(new \App\Jobs\WeeklySpendDigestJob)->weeklyOn(1, '07:30')->withoutOverlapping();

// Knowledge brain (ADR-0002 D2): hourly re-projection of business context for tenants whose source tables changed
Schedule::job(new \App\Jobs\BrainSyncJob)->hourly()->withoutOverlapping();

// Operating brain (ADR-0002): free agent runs whose worker lease expired.
Schedule::job(new \App\Jobs\FailStaleAgentRunsJob)->everyTenMinutes()->withoutOverlapping();
