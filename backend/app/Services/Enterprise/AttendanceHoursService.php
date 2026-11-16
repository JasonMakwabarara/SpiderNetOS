<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Models\AttendanceSite;
use App\Models\ClockEvent;
use App\Models\Employee;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceHoursService
{
    public function dailyMinutes(string $tenantId): int
    {
        $tenant = Tenant::query()->find($tenantId);
        $settings = is_array($tenant?->settings) ? $tenant->settings : [];
        $minutes = (int) ($settings['hr']['daily_minutes'] ?? 480);

        return $minutes > 0 ? $minutes : 480;
    }

    /**
     * @return array{payable_minutes: int, overtime_minutes: int, days_worked: list<string>, missing_out: int}
     */
    public function summarize(string $tenantId, string $employeeId, string $from, string $to): array
    {
        $zone = $this->timezone($tenantId);
        $start = Carbon::parse($from, $zone)->startOfDay();
        $end = Carbon::parse($to, $zone)->endOfDay();
        $events = ClockEvent::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->whereBetween('recorded_at', [$start, $end])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return $this->pair($events, $this->dailyMinutes($tenantId), $zone);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(string $tenantId, string $from, string $to): array
    {
        $zone = $this->timezone($tenantId);
        $start = Carbon::parse($from, $zone)->startOfDay();
        $end = Carbon::parse($to, $zone)->endOfDay();
        $threshold = $this->dailyMinutes($tenantId);
        $events = ClockEvent::forTenant($tenantId)
            ->whereBetween('recorded_at', [$start, $end])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $employees = Employee::forTenant($tenantId)->get()->keyBy('id');
        $totals = [];
        foreach ($events as $employeeId => $rows) {
            $summary = $this->pair($rows, $threshold, $zone);
            $employee = $employees->get($employeeId);
            $totals[] = [
                'employee_id' => $employeeId,
                'employee_number' => $employee?->employee_number,
                'name' => $employee?->name,
                'payable_minutes' => $summary['payable_minutes'],
                'overtime_minutes' => $summary['overtime_minutes'],
                'days_worked' => $summary['days_worked'],
                'missing_out' => $summary['missing_out'],
            ];
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'daily_minutes' => $threshold,
            'present' => $this->present($tenantId),
            'totals' => $totals,
            'sites' => AttendanceSite::forTenant($tenantId)
                ->orderBy('name')
                ->get(['id', 'name', 'last_synced_at', 'sync_cursor']),
        ];
    }

    /**
     * @return list<array{id: string, name: string, last_synced_at: mixed}>
     */
    public function unsyncedSites(string $tenantId, string $periodEnd): array
    {
        $end = Carbon::parse($periodEnd)->endOfDay();

        return AttendanceSite::forTenant($tenantId)
            ->orderBy('name')
            ->get()
            ->filter(function (AttendanceSite $site) use ($end) {
                return $site->last_synced_at === null || $site->last_synced_at->lt($end);
            })
            ->map(fn (AttendanceSite $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'last_synced_at' => $site->last_synced_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ClockEvent>  $events
     * @return array{payable_minutes: int, overtime_minutes: int, days_worked: list<string>, missing_out: int}
     */
    private function pair(Collection $events, int $threshold, string $zone): array
    {
        $open = null;
        $byDay = [];
        $missing = 0;
        foreach ($events as $event) {
            if ($event->type === 'in') {
                if ($open) {
                    $missing++;
                }
                $open = $event;
                continue;
            }
            if (! $open) {
                $missing++;
                continue;
            }
            $minutes = (int) max(0, round(($event->recorded_at->getTimestamp() - $open->recorded_at->getTimestamp()) / 60));
            $day = $open->recorded_at->copy()->timezone($zone)->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0) + $minutes;
            $open = null;
        }
        if ($open) {
            $missing++;
        }

        $payable = 0;
        $overtime = 0;
        foreach ($byDay as $minutes) {
            $payable += $minutes;
            $overtime += max(0, $minutes - $threshold);
        }

        return [
            'payable_minutes' => $payable,
            'overtime_minutes' => $overtime,
            'days_worked' => array_keys($byDay),
            'missing_out' => $missing,
        ];
    }

    /**
     * @return list<array{employee_id: string, employee_number: ?string, name: ?string, recorded_at: mixed}>
     */
    private function present(string $tenantId): array
    {
        $latest = ClockEvent::forTenant($tenantId)
            ->selectRaw('employee_id, max(recorded_at) as recorded_at')
            ->groupBy('employee_id')
            ->get();
        $present = [];
        foreach ($latest as $row) {
            $event = ClockEvent::forTenant($tenantId)
                ->where('employee_id', $row->employee_id)
                ->where('recorded_at', $row->recorded_at)
                ->orderByDesc('id')
                ->first();
            if ($event?->type !== 'in') {
                continue;
            }
            $employee = Employee::forTenant($tenantId)->find($row->employee_id);
            $present[] = [
                'employee_id' => $row->employee_id,
                'employee_number' => $employee?->employee_number,
                'name' => $employee?->name,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ];
        }

        return $present;
    }

    private function timezone(string $tenantId): string
    {
        $tenant = Tenant::query()->find($tenantId);
        $settings = is_array($tenant?->settings) ? $tenant->settings : [];
        $zone = $settings['timezone'] ?? config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : (string) config('app.timezone');
    }
}
