<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HrAuditEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\EventStore;
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
            'next_employee_number' => $this->documentNumbers->preview($tenantId, 'employee', $prefix.'-', $pad),
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

    public function suggestJobDescription(string $positionTitle): string
    {
        $title = trim($positionTitle);
        $known = [
            'procurement officer' => 'Responsible for supplier sourcing, quotation comparison, purchase-order administration, procurement documentation, supplier coordination, and compliance with organizational purchasing procedures.',
            'accountant' => 'Responsible for recording transactions, reconciling accounts, preparing management reports, and keeping financial records accurate and complete.',
            'driver' => 'Responsible for safe operation of assigned vehicles, scheduled trips, vehicle condition reporting, and custody of keys and fuel records.',
        ];

        return $known[strtolower($title)]
            ?? "Responsible for the duties of {$title}, including the day-to-day work, records, and coordination expected of that position.";
    }

    public function createEmployee(string $tenantId, array $data, ?string $actorId): Employee
    {
        $this->assertPay($data);
        $this->assertReportsTo($tenantId, null, $data['reports_to'] ?? null);
        $this->assertLinkedUser($tenantId, $data['user_id'] ?? null);

        try {
            $employee = DB::transaction(function () use ($tenantId, $data, $actorId) {
                $this->assertDepartment($tenantId, $data['department_id'] ?? null);
                [$prefix, $pad] = $this->numbering($tenantId);
                $number = $this->documentNumbers->next($tenantId, 'employee', $prefix.'-', $pad);
                $jobDescription = trim((string) ($data['job_description'] ?? ''));
                if ($jobDescription === '') {
                    $jobDescription = $this->suggestJobDescription($data['position_title']);
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
                    'employment_type' => $data['employment_type'] ?? 'permanent',
                    'pay_basis' => $data['pay_basis'] ?? 'monthly',
                    'pay_rate' => number_format((float) ($data['pay_rate'] ?? 0), 2, '.', ''),
                    'pay_currency' => strtoupper((string) ($data['pay_currency'] ?? 'USD')),
                    'national_id' => $data['national_id'] ?? null,
                    'nssa_number' => $data['nssa_number'] ?? null,
                    'reports_to' => $data['reports_to'] ?? null,
                    'user_id' => $data['user_id'] ?? null,
                    'jetson_person_id' => $data['jetson_person_id'] ?? null,
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
                foreach (['employment_type', 'pay_basis', 'pay_rate', 'pay_currency', 'national_id', 'nssa_number', 'reports_to', 'user_id', 'jetson_person_id'] as $field) {
                    if ($employee->{$field} !== null && $employee->{$field} !== '') {
                        $changes[$field] = [null, $employee->{$field}];
                    }
                }

                $this->auditFields($tenantId, $employee->id, $actorId, 'created', $changes);
                $this->structural($tenantId, $employee->id, 'enterprise.employee.created', array_keys($changes), $actorId);

                return $employee;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new DomainException($this->uniqueMessage($e));
        }

        return $employee->load('department');
    }

    /**
     * Match a person recorded on a Jetson Pi, or create one and return the server employee number.
     *
     * @param  array{jetson_person_id: string, first_name: string, surname: string, employee_number?: string|null}  $person
     */
    public function upsertFromJetson(string $tenantId, array $person): Employee
    {
        return DB::transaction(function () use ($tenantId, $person) {
            $localId = trim((string) $person['jetson_person_id']);
            $existingByLocal = Employee::forTenant($tenantId)->where('jetson_person_id', $localId)->lockForUpdate()->first();
            $byNumber = null;
            $number = trim((string) ($person['employee_number'] ?? ''));
            if ($number !== '') {
                $byNumber = Employee::forTenant($tenantId)->where('employee_number', $number)->lockForUpdate()->first();
            }
            if ($existingByLocal && $byNumber && $existingByLocal->id !== $byNumber->id) {
                throw new DomainException('identity_conflict');
            }

            $employee = $existingByLocal ?: $byNumber;
            if (! $employee) {
                $employee = $this->createEmployee($tenantId, [
                    'first_name' => $person['first_name'],
                    'surname' => $person['surname'],
                    'position_title' => 'Employee',
                    'jetson_person_id' => $localId,
                ], null);

                return $employee;
            }

            if (! $employee->jetson_person_id) {
                $employee->jetson_person_id = $localId;
                $employee->save();
            }

            return $this->updateEmployee($tenantId, $employee->id, [
                'first_name' => $person['first_name'],
                'surname' => $person['surname'],
            ], null);
        });
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
            if (array_key_exists('reports_to', $data)) {
                $this->assertReportsTo($tenantId, $employeeId, $data['reports_to']);
            }
            if (array_key_exists('user_id', $data)) {
                $this->assertLinkedUser($tenantId, $data['user_id']);
            }
            if (array_key_exists('pay_currency', $data) && $data['pay_currency']) {
                $data['pay_currency'] = Money::code((string) $data['pay_currency']);
            }
            $this->assertPay($data);

            $changes = [];
            $fields = [
                'first_name', 'surname', 'position_title', 'job_description', 'department_id', 'start_date',
                'employment_type', 'pay_basis', 'pay_rate', 'pay_currency', 'national_id', 'nssa_number',
                'reports_to', 'user_id', 'jetson_person_id',
            ];
            foreach ($fields as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $next = in_array($field, ['start_date', 'department_id', 'reports_to', 'user_id', 'pay_rate'], true)
                    ? $data[$field]
                    : (is_string($data[$field]) ? trim($data[$field]) : $data[$field]);
                if ($field === 'pay_rate') {
                    $next = number_format((float) $next, 2, '.', '');
                }
                if ($next === '') {
                    $next = null;
                }
                $current = $employee->{$field};
                $currentValue = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
                if ($field === 'pay_rate') {
                    $currentValue = number_format((float) $currentValue, 2, '.', '');
                }
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPay(array $data): void
    {
        if (array_key_exists('employment_type', $data) && ! in_array($data['employment_type'], ['permanent', 'casual'], true)) {
            throw new DomainException('Employment type must be permanent or casual.');
        }
        if (array_key_exists('pay_basis', $data) && ! in_array($data['pay_basis'], ['monthly', 'daily', 'hourly'], true)) {
            throw new DomainException('Pay basis must be monthly, daily, or hourly.');
        }
        if (array_key_exists('pay_rate', $data) && $data['pay_rate'] !== null && (float) $data['pay_rate'] < 0) {
            throw new DomainException('Pay rate cannot be negative.');
        }
    }

    private function assertReportsTo(string $tenantId, ?string $employeeId, ?string $reportsTo): void
    {
        if (! $reportsTo) {
            return;
        }
        if ($employeeId && $reportsTo === $employeeId) {
            throw new DomainException('An employee cannot report to themselves.');
        }
        Employee::forTenant($tenantId)->findOrFail($reportsTo);
    }

    private function assertLinkedUser(string $tenantId, ?string $userId): void
    {
        if (! $userId) {
            return;
        }
        User::query()->where('tenant_id', $tenantId)->findOrFail($userId);
    }

    private function uniqueMessage(UniqueConstraintViolationException $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'user')) {
            return 'That user is already linked to an employee.';
        }
        if (str_contains($message, 'jetson')) {
            return 'That Jetson person is already linked to an employee.';
        }

        return 'Employee number already exists for this tenant.';
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
