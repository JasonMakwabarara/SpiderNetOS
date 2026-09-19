<?php

namespace App\Services\Projections;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsageProjection
{
    public function accepts(Event $event): bool
    {
        return in_array($event->event_type, [
            'usage.recorded',
            'usage.budget_exceeded',
        ]);
    }

    public function handle(Event $event): void
    {
        match ($event->event_type) {
            'usage.recorded' => $this->handleRecorded($event),
            'usage.budget_exceeded' => $this->handleBudgetExceeded($event),
            default => null,
        };
    }

    private function handleRecorded(Event $event): void
    {
        $p = $event->payload;

        DB::table('usage_records')->insert([
            'id' => $p['record_id'] ?? (string) Str::uuid(),
            'tenant_id' => $event->tenant_id,
            'user_id' => $p['user_id'] ?? null,
            'agent_id' => $p['agent_id'] ?? null,
            'resource_type' => $p['resource_type'],
            'model' => $p['model'] ?? null,
            'tokens_input' => $p['tokens_input'] ?? 0,
            'tokens_output' => $p['tokens_output'] ?? 0,
            'cost_usd' => $p['cost_usd'] ?? 0,
            'duration_ms' => $p['duration_ms'] ?? null,
            'status' => $p['status'] ?? 'success',
            'recorded_at' => $event->occurred_at,
            'metadata' => json_encode($p['metadata'] ?? []),
        ]);
    }

    private function handleBudgetExceeded(Event $event): void
    {
        // Budget exceeded events are logged but don't modify projections
        // The CostGovernor handles enforcement at dispatch time
    }
}
