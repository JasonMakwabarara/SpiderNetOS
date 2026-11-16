<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\ClockEvent;
use App\Models\Employee;
use App\Services\EventStore;
use Illuminate\Database\UniqueConstraintViolationException;

class AttendanceService
{
    public function __construct(private readonly EventStore $eventStore) {}

    /**
     * @param  array{source?: string, attendance_site_id?: string, attendance_device_id?: string, external_event_id?: string, sensor_id?: string}  $meta
     */
    public function record(string $tenantId, string $employeeId, string $type, ?string $recordedAt = null, array $meta = []): ClockEvent
    {
        if (! in_array($type, ['in', 'out'], true)) {
            throw new DomainException('Clock event type must be in or out.');
        }

        Employee::forTenant($tenantId)->findOrFail($employeeId);

        $externalId = isset($meta['external_event_id']) && $meta['external_event_id'] !== ''
            ? (string) $meta['external_event_id']
            : null;
        if ($externalId) {
            $existing = ClockEvent::forTenant($tenantId)->where('external_event_id', $externalId)->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            $event = ClockEvent::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'type' => $type,
                'recorded_at' => $recordedAt ?? now(),
                'source' => $meta['source'] ?? 'manual',
                'attendance_site_id' => $meta['attendance_site_id'] ?? null,
                'attendance_device_id' => $meta['attendance_device_id'] ?? null,
                'external_event_id' => $externalId,
                'sensor_id' => $meta['sensor_id'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = $externalId
                ? ClockEvent::forTenant($tenantId)->where('external_event_id', $externalId)->first()
                : null;
            if ($existing) {
                return $existing;
            }
            throw new DomainException('Clock event already exists.');
        }

        $this->eventStore->append($tenantId, 'clock_event', $event->id, 'enterprise.attendance.clock_recorded', [
            'clock_event_id' => $event->id,
            'employee_id' => $employeeId,
            'type' => $type,
        ]);

        return $event;
    }
}
