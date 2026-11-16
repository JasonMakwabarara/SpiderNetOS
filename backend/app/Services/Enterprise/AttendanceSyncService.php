<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\AttendanceDevice;
use App\Models\AttendanceSite;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AttendanceSyncService
{
    public function __construct(
        private readonly HrService $hr,
        private readonly AttendanceService $attendance,
    ) {}

    public function createSite(string $tenantId, string $name, string $secret): AttendanceSite
    {
        return AttendanceSite::create([
            'tenant_id' => $tenantId,
            'name' => trim($name),
            'secret_hash' => hash('sha256', $secret),
        ]);
    }

    public function revokeDevice(string $tenantId, string $deviceId): AttendanceDevice
    {
        $device = AttendanceDevice::forTenant($tenantId)->findOrFail($deviceId);
        $device->status = 'revoked';
        $device->save();

        return $device;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sync(AttendanceSite $site, array $payload): array
    {
        $tenantId = (string) $site->tenant_id;
        $acceptedPeople = [];
        $acceptedDevices = [];
        $acceptedEvents = [];
        $rejected = [];
        $known = [];

        foreach ($payload['people'] ?? [] as $person) {
            try {
                $employee = $this->hr->upsertFromJetson($tenantId, $person);
                $known[$person['jetson_person_id']] = $employee;
                $acceptedPeople[] = [
                    'jetson_person_id' => $person['jetson_person_id'],
                    'employee_id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                ];
            } catch (DomainException $e) {
                $rejected[] = [
                    'kind' => 'person',
                    'jetson_person_id' => $person['jetson_person_id'] ?? null,
                    'code' => $e->getMessage() === 'identity_conflict' ? 'identity_conflict' : 'rejected',
                ];
            } catch (ModelNotFoundException) {
                $rejected[] = [
                    'kind' => 'person',
                    'jetson_person_id' => $person['jetson_person_id'] ?? null,
                    'code' => 'rejected',
                ];
            }
        }

        foreach ($payload['devices'] ?? [] as $device) {
            $person = $known[$device['jetson_person_id']] ?? Employee::forTenant($tenantId)
                ->where('jetson_person_id', $device['jetson_person_id'])
                ->first();
            if (! $person) {
                $rejected[] = [
                    'kind' => 'device',
                    'external_device_id' => $device['external_device_id'],
                    'code' => 'person_unknown',
                ];
                continue;
            }
            $row = AttendanceDevice::forTenant($tenantId)
                ->where('external_device_id', $device['external_device_id'])
                ->first();
            if ($row && $row->status === 'revoked') {
                $acceptedDevices[] = [
                    'external_device_id' => $row->external_device_id,
                    'status' => 'revoked',
                    'employee_id' => $row->employee_id,
                ];
                continue;
            }
            if (! $row) {
                $row = AttendanceDevice::create([
                    'tenant_id' => $tenantId,
                    'attendance_site_id' => $site->id,
                    'employee_id' => $person->id,
                    'external_device_id' => $device['external_device_id'],
                    'status' => 'active',
                ]);
            } else {
                $row->employee_id = $person->id;
                $row->attendance_site_id = $site->id;
                $row->status = 'active';
                $row->save();
            }
            $acceptedDevices[] = [
                'external_device_id' => $row->external_device_id,
                'status' => $row->status,
                'employee_id' => $row->employee_id,
            ];
        }

        foreach ($payload['clock_events'] ?? [] as $punch) {
            $device = AttendanceDevice::forTenant($tenantId)
                ->where('external_device_id', $punch['external_device_id'])
                ->first();
            if (! $device) {
                $rejected[] = [
                    'kind' => 'clock_event',
                    'external_event_id' => $punch['external_event_id'],
                    'code' => 'device_unknown',
                ];
                continue;
            }
            if ($device->status !== 'active') {
                $rejected[] = [
                    'kind' => 'clock_event',
                    'external_event_id' => $punch['external_event_id'],
                    'code' => 'device_inactive',
                ];
                continue;
            }
            $employee = Employee::forTenant($tenantId)->find($device->employee_id);
            if (! $employee || $employee->status !== 'active') {
                $rejected[] = [
                    'kind' => 'clock_event',
                    'external_event_id' => $punch['external_event_id'],
                    'code' => 'employee_inactive',
                ];
                continue;
            }
            $event = $this->attendance->record(
                $tenantId,
                $employee->id,
                $punch['type'],
                Carbon::parse($punch['recorded_at'])->toIso8601String(),
                [
                    'source' => 'jetson',
                    'attendance_site_id' => $site->id,
                    'attendance_device_id' => $device->id,
                    'external_event_id' => $punch['external_event_id'],
                    'sensor_id' => $punch['sensor_id'] ?? null,
                ],
            );
            $acceptedEvents[] = [
                'external_event_id' => $punch['external_event_id'],
                'id' => $event->id,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ];
        }

        $cursor = isset($payload['cursor']) ? Carbon::parse($payload['cursor']) : null;
        $employeeQuery = Employee::forTenant($tenantId)->orderBy('updated_at')->orderBy('id');
        $deviceQuery = AttendanceDevice::forTenant($tenantId)->with('employee')->orderBy('updated_at')->orderBy('id');
        if ($cursor) {
            $employeeQuery->where('updated_at', '>', $cursor);
            $deviceQuery->where('updated_at', '>', $cursor);
        }
        $employees = $employeeQuery->limit(501)->get();
        $devices = $deviceQuery->limit(501)->get();
        $employeeMore = $employees->count() > 500;
        $deviceMore = $devices->count() > 500;
        if ($employeeMore) {
            $employees = $employees->take(500);
        }
        if ($deviceMore) {
            $devices = $devices->take(500);
        }
        $hasMore = $employeeMore || $deviceMore;
        $next = now();
        if ($employeeMore && $employees->last()?->updated_at) {
            $next = $employees->last()->updated_at;
        }
        if ($deviceMore && $devices->last()?->updated_at && (! $employeeMore || $devices->last()->updated_at->lt($next))) {
            $next = $devices->last()->updated_at;
        }

        $site->sync_cursor = $next->toIso8601String();
        if (($payload['caught_up'] ?? false) && ! $hasMore) {
            $site->last_synced_at = now();
        }
        $site->save();

        return [
            'cursor' => $site->sync_cursor,
            'has_more' => $hasMore,
            'last_synced_at' => $site->last_synced_at?->toIso8601String(),
            'accepted' => [
                'people' => $acceptedPeople,
                'devices' => $acceptedDevices,
                'clock_events' => $acceptedEvents,
            ],
            'rejected' => $rejected,
            'register' => [
                'employees' => $employees->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'first_name' => $employee->first_name,
                    'surname' => $employee->surname,
                    'status' => $employee->status,
                    'jetson_person_id' => $employee->jetson_person_id,
                    'updated_at' => $employee->updated_at?->toIso8601String(),
                ])->values(),
                'devices' => $devices->map(fn (AttendanceDevice $device) => [
                    'id' => $device->id,
                    'external_device_id' => $device->external_device_id,
                    'employee_id' => $device->employee_id,
                    'employee_number' => $device->employee?->employee_number,
                    'status' => $device->status,
                    'updated_at' => $device->updated_at?->toIso8601String(),
                ])->values(),
            ],
        ];
    }
}
