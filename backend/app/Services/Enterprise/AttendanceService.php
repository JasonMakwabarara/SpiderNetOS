<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\ClockEvent;
use App\Models\Employee;
use App\Services\EventStore;

class AttendanceService
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function record(string $tenantId, string $employeeId, string $type, ?string $recordedAt = null): ClockEvent
    {
        if (! in_array($type, ['in', 'out'], true)) {
            throw new DomainException('Clock event type must be in or out.');
        }

        Employee::forTenant($tenantId)->findOrFail($employeeId);

        $event = ClockEvent::create([
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'type' => $type,
            'recorded_at' => $recordedAt ?? now(),
        ]);

        $this->eventStore->append($tenantId, 'clock_event', $event->id, 'enterprise.attendance.clock_recorded', [
            'clock_event_id' => $event->id,
            'employee_id' => $employeeId,
            'type' => $type,
        ]);

        return $event;
    }
}
