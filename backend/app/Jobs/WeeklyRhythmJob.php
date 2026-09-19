<?php

namespace App\Jobs;

use App\Models\AwarenessItem;
use App\Models\WeeklyRhythm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Priestley "Activity" A — the perfect repeatable week.
 *
 * Monday ('priorities'): dispatches atlas.weekly_priorities per tenant so it
 * can propose 3-6 priorities from scoreboard gaps + open awareness items;
 * ensures a weekly_rhythms row exists for the week for the owner to review/edit.
 *
 * Friday ('checkin'): dispatches atlas.weekly_checkin with the week's
 * priorities so it can draft a done-vs-planned narrative and surface one
 * "locking antlers" point of creative disagreement where data contradicts
 * the owner's stated priorities.
 */
class WeeklyRhythmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(private readonly string $mode) {}

    public function handle(): void
    {
        $tenants = DB::table('tenants')->where('status', 'active')->pluck('id');
        $weekStart = now()->startOfWeek()->toDateString();
        $dispatched = 0;

        foreach ($tenants as $tenantId) {
            $rhythm = WeeklyRhythm::firstOrCreate(
                ['tenant_id' => $tenantId, 'week_start' => $weekStart],
                ['priorities' => [], 'checkin' => null],
            );

            $openAwareness = AwarenessItem::forTenant($tenantId)->open()->count();

            Redis::rpush('agent:dispatch', json_encode([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'agent_id' => 'atlas',
                'intent' => $this->mode === 'checkin' ? 'weekly_checkin' : 'weekly_priorities',
                'context' => [
                    'week_start' => $weekStart,
                    'weekly_rhythm_id' => $rhythm->id,
                    'open_awareness_count' => $openAwareness,
                    'existing_priorities' => $rhythm->priorities,
                ],
                'priority' => 'low',
                'version' => '3.2',
            ]));
            $dispatched++;
        }

        Log::info("[WeeklyRhythm:{$this->mode}] Dispatched {$dispatched} message(s) for week {$weekStart}.");
    }
}
