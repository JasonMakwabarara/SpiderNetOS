<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Daily Brief Generation (06:00 daily)
Schedule::job(new \App\Jobs\GenerateDailyBriefJob)->dailyAt('06:00');

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
