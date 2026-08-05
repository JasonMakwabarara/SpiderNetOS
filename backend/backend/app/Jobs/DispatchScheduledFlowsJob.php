<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DispatchScheduledFlowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $flows = DB::table('flows')
            ->where('status', 'published')
            ->whereNotNull('schedule_cron')
            ->get(['id', 'tenant_id', 'schedule_cron', 'schedule_timezone', 'last_scheduled_at', 'triggers']);

        $now = now();

        foreach ($flows as $flow) {
            $cron = (string) $flow->schedule_cron;
            if ($cron === '') {
                continue;
            }

            if (! $this->isDue($cron, $flow->last_scheduled_at, $now)) {
                continue;
            }

            $triggers = json_decode($flow->triggers ?? '[]', true) ?: [];
            $context = (array) ($triggers['context'] ?? []);

            ExecuteFlowJob::dispatch(
                tenantId: (string) $flow->tenant_id,
                flowId: (string) $flow->id,
                context: $context,
            );

            DB::table('flows')->where('id', $flow->id)->update([
                'last_scheduled_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function isDue(string $cron, mixed $lastRun, \Illuminate\Support\Carbon $now): bool
    {
        if ($lastRun === null) {
            return true;
        }

        try {
            $last = \Illuminate\Support\Carbon::parse($lastRun);
        } catch (\Throwable) {
            return true;
        }

        // Simple schedules used by first-win wizard
        return match ($cron) {
            'daily_morning' => $last->diffInHours($now) >= 20,
            'weekday_morning' => $last->diffInHours($now) >= 20 && $now->isWeekday(),
            'hourly' => $last->diffInMinutes($now) >= 55,
            default => $last->diffInMinutes($now) >= 5,
        };
    }
}
