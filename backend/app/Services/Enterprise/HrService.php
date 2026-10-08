<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HrAuditEntry;
use App\Models\JobDescriptionTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class HrService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function createDepartment(string $tenantId, string $name): Department
    {
        $department = Department::create([
            'tenant_id' => $tenantId,
            'name' => $name,
        ]);

        $this->eventStore->append($tenantId, 'department', $department->id, 'enterprise.department.created', [
            'department_id' => $department->id,
        ]);

        return $department;
    }

    /**
     * @return array{employee_number_prefix: string, employee_number_padding: int, next_employee_number: string}
     */
    public function numberingSettings(string $tenantId): array
    {
        [$prefix, $pad] = $this->numbering($tenantId);

        return [
            'employee_number_prefix' => $prefix,
            'employee_number_padding' => $pad,
            'next_employee_number' => $this->documentNumbers->previewSerial($tenantId, 'employee', $prefix, $pad),
        ];
    }

    /**
     * @return array{employee_number_prefix: string, employee_number_padding: int, next_employee_number: string}
     */
    public function updateNumberingSettings(string $tenantId, string $prefix, int $pad): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $settings['hr'] = [
            'employee_number_prefix' => strtoupper($prefix),
            'employee_number_padding' => $pad,
        ];
        $tenant->settings = $settings;
        $tenant->save();

        return $this->numberingSettings($tenantId);
    }

    public function suggestJobDescription(string $tenantId, string $positionTitle): string
    {
        $title = trim($positionTitle);
        $saved = JobDescriptionTemplate::forTenant($tenantId)
            ->where('title_key', mb_strtolower($title))
            ->value('body');
        if (is_string($saved) && $saved !== '') {
            return $saved;
        }

        $known = [
            'procurement officer' => 'Responsible for supplier sourcing, quotation comparison, purchase-order administration, procurement documentation, supplier coordination, and compliance with organizational purchasing procedures.',
            'accountant' => 'Responsible for recording transactions, reconciling accounts, preparing management reports, and keeping financial records accurate and complete.',
            'driver' => 'Responsible for safe operation of assigned vehicles, scheduled trips, vehicle condition reporting, and custody of keys and fuel records.',
            'receptionist' => 'Responsible for receiving visitors, handling incoming calls and messages, keeping the front desk record, and directing people to the right office.',
            'stores clerk' => 'Responsible for receiving goods, checking quantities against documents, storing stock, and issuing items against authorised requests.',
            'security officer' => 'Responsible for access control, patrol of the premises, incident recording, and custody of keys issued for the shift.',
            'human resources officer' => 'Responsible for the employee register, leave records, contract files, and keeping personal records limited to people who need them.',
            'workshop supervisor' => 'Responsible for assigning workshop tasks, checking completed work, recording tools issued, and reporting equipment that is not fit for use.',
        ];

        return $known[strtolower($title)]
            ?? "Responsible for the duties of {$title}, including the day-to-day work, records, and coordination expected of that position.";
    }

    public function createEmployee(string $tenantId, array $data, ?string $actorId): Employee
    {
        try {
            $employee = DB::transaction(function () use ($tenantId, $data, $actorId) {
                $this->assertDepartment($tenantId, $data['department_id'] ?? null);
                [$prefix, $pad] = $this->numbering($tenantId);
                $number = $this->documentNumbers->nextSerial($tenantId, 'employee', $prefix, $pad);
                $jobDescription = trim((string) ($data['job_description'] ?? ''));
                if ($jobDescription === '') {
                    $jobDescription = $this->suggestJobDescription($tenantId, $data['position_title']);
                }

                $employee = Employee::create([
                    'tenant_id' => $tenantId,
                    'department_id' => $data['department_id'] ?? null,
                    'employee_number' => $number,
                    'first_name' => trim($data['first_name']),
                    'surname' => trim($data['surname']),
                    'name' => $this->displayName($data['first_name'], $data['surname']),
                    'position_title' => trim($data['position_title']),
                    'job_description' => $jobDescription,
                    'start_date' => $data['start_date'] ?? null,
                    'status' => 'active',
                ]);

                $changes = [
                    'employee_number' => [null, $employee->employee_number],
                    'first_name' => [null, $employee->first_name],
                    'surname' => [null, $employee->surname],
                    'name' => [null, $employee->name],
                    'position_title' => [null, $employee->position_title],
                    'job_description' => [null, $employee->job_description],
                    'status' => [null, 'active'],
                ];
                if ($employee->department_id) {
                    $changes['department_id'] = [null, $employee->department_id];
                }
                if ($employee->start_date) {
                    $changes['start_date'] = [null, $employee->start_date->toDateString()];
                }

                $this->auditFields($tenantId, $employee->id, $actorId, 'created', $changes);
                $this->structural($tenantId, $employee->id, 'enterprise.employee.created', array_keys($changes), $actorId);

                return $employee;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('Employee number already exists for this tenant.');
        }

        return $employee->load('department');
    }

    public function search(string $tenantId, array $filters): LengthAwarePaginator
    {
        $status = $filters['status'] ?? 'active';
        $term = trim((string) ($filters['q'] ?? ''));

        $query = Employee::forTenant($tenantId)->with('department')->orderBy('employee_number');

        if ($status !== 'all') {
            $query->where('employees.status', $status);
        }

        if (! empty($filters['department_id'])) {
            $query->where('employees.department_id', $filters['department_id']);
        }

        if ($term !== '') {
            $needle = '%'.mb_strtolower($term).'%';
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('lower(employees.employee_number) like ?', [$needle])
                    ->orWhereRaw('lower(employees.first_name) like ?', [$needle])
                    ->orWhereRaw('lower(employees.surname) like ?', [$needle])
                    ->orWhereRaw('lower(employees.name) like ?', [$needle])
                    ->orWhereRaw('lower(employees.position_title) like ?', [$needle])
                    ->orWhereHas('department', function ($department) use ($needle) {
                        $department->whereRaw('lower(departments.name) like ?', [$needle]);
                    });
            });
        }

        return $query->paginate(25);
    }

    public function updateEmployee(string $tenantId, string $employeeId, array $data, ?string $actorId): Employee
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $data, $actorId) {
            $employee = Employee::forTenant($tenantId)->lockForUpdate()->findOrFail($employeeId);
            if (array_key_exists('department_id', $data)) {
                $this->assertDepartment($tenantId, $data['department_id']);
            }

            $changes = [];
            foreach (['first_name', 'surname', 'position_title', 'job_description', 'department_id', 'start_date'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $next = $field === 'start_date' || $field === 'department_id'
                    ? $data[$field]
                    : (is_string($data[$field]) ? trim($data[$field]) : $data[$field]);
                $current = $employee->{$field};
                $currentValue = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
                $nextValue = $next instanceof \DateTimeInterface ? $next->format('Y-m-d') : $next;
                if ((string) $currentValue !== (string) $nextValue) {
                    $changes[$field] = [$currentValue, $nextValue];
                    $employee->{$field} = $next;
                }
            }

            $display = $this->displayName($employee->first_name, $employee->surname);
            if ($display !== $employee->name) {
                $changes['name'] = [$employee->name, $display];
                $employee->name = $display;
            }

            if ($changes === []) {
                return $employee->load('department');
            }

            $employee->save();
            $this->auditFields($tenantId, $employee->id, $actorId, 'updated', $changes);
            $this->structural($tenantId, $employee->id, 'enterprise.employee.updated', array_keys($changes), $actorId);

            return $employee->load('department');
        });
    }

    public function deactivate(string $tenantId, string $employeeId, ?string $inactiveFrom, ?string $reason, ?string $actorId): Employee
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $inactiveFrom, $reason, $actorId) {
            $employee = Employee::forTenant($tenantId)->lockForUpdate()->findOrFail($employeeId);
            if ($employee->status === 'inactive') {
                throw new DomainException('Employee is already inactive.');
            }

            $from = $inactiveFrom ?: now($this->timezone($tenantId))->toDateString();
            $changes = [
                'status' => [$employee->status, 'inactive'],
                'inactive_from' => [$employee->inactive_from?->toDateString(), $from],
                'inactive_reason' => [$employee->inactive_reason, $reason],
            ];

            $employee->status = 'inactive';
            $employee->inactive_from = $from;
            $employee->inactive_reason = $reason;
            $employee->save();

            $this->auditFields($tenantId, $employee->id, $actorId, 'deactivated', $changes);
            $this->structural($tenantId, $employee->id, 'enterprise.employee.deactivated', array_keys($changes), $actorId);

            return $employee->load('department');
        });
    }

    public function exportAuditCsv(string $tenantId, string $employeeId, ?string $actorId): string
    {
        $employee = Employee::forTenant($tenantId)->findOrFail($employeeId);
        $rows = HrAuditEntry::forTenant($tenantId)
            ->where('employee_id', $employee->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Timestamp', 'Employee number', 'Event', 'Field changed', 'Previous value', 'New value', 'Actor']);
        foreach ($rows as $row) {
            $actor = $row->actor_user_id ? User::query()->find($row->actor_user_id) : null;
            fputcsv($handle, [
                optional($row->created_at)->toIso8601String(),
                $this->csvCell($employee->employee_number),
                $this->csvCell($row->event),
                $this->csvCell($row->field),
                $this->csvCell($row->previous_value),
                $this->csvCell($row->new_value),
                $this->csvCell($actor?->name),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        HrAuditEntry::create([
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'actor_user_id' => $actorId,
            'event' => 'audit_exported',
            'created_at' => now(),
        ]);
        $this->structural($tenantId, $employee->id, 'hr.audit_exported', [], $actorId);

        return $csv;
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private function auditFields(string $tenantId, string $employeeId, ?string $actorId, string $event, array $changes): void
    {
        foreach ($changes as $field => [$previous, $next]) {
            HrAuditEntry::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'actor_user_id' => $actorId,
                'event' => $event,
                'field' => $field,
                'previous_value' => $previous === null ? null : (string) $previous,
                'new_value' => $next === null ? null : (string) $next,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<string>  $fields
     */
    private function structural(string $tenantId, string $employeeId, string $eventType, array $fields, ?string $actorId): void
    {
        $this->eventStore->append($tenantId, 'employee', $employeeId, $eventType, [
            'employee_id' => $employeeId,
            'fields_changed' => array_values($fields),
            'actor_id' => $actorId,
        ]);
    }

    private function assertDepartment(string $tenantId, ?string $departmentId): void
    {
        if ($departmentId) {
            Department::forTenant($tenantId)->findOrFail($departmentId);
        }
    }

    private function displayName(string $firstName, string $surname): string
    {
        return trim(trim($firstName).' '.trim($surname));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function numbering(string $tenantId): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $hr = is_array($tenant->settings) ? ($tenant->settings['hr'] ?? []) : [];
        $prefix = strtoupper((string) ($hr['employee_number_prefix'] ?? 'EMP'));
        $pad = (int) ($hr['employee_number_padding'] ?? 4);

        return [$prefix !== '' ? $prefix : 'EMP', $pad > 0 ? $pad : 4];
    }

    private function timezone(string $tenantId): string
    {
        $tenant = Tenant::query()->find($tenantId);
        $settings = is_array($tenant?->settings) ? $tenant->settings : [];
        $zone = $settings['timezone'] ?? config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : (string) config('app.timezone');
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && preg_match('/^[=+\-@]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }
}
