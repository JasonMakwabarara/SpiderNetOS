<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Employee;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use Illuminate\Support\Facades\DB;

class RequisitionService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
        private readonly ApprovalEngine $approvalEngine,
    ) {}

    public function create(string $tenantId, string $requesterId, array $data): Requisition
    {
        $lines = $data['lines'] ?? [];
        if ($lines === []) {
            throw new DomainException('A requisition needs at least one line.');
        }

        if (! empty($data['employee_id'])) {
            Employee::forTenant($tenantId)->findOrFail($data['employee_id']);
        }

        return DB::transaction(function () use ($tenantId, $data, $lines) {
            $requisition = Requisition::create([
                'tenant_id' => $tenantId,
                'employee_id' => $data['employee_id'] ?? null,
                'requisition_number' => $this->documentNumbers->nextSerial($tenantId, 'requisition', 'REQ', 6),
                'title' => $data['title'],
                'status' => 'draft',
            ]);

            foreach ($lines as $line) {
                RequisitionLine::create([
                    'requisition_id' => $requisition->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);
            }

            $this->eventStore->append($tenantId, 'requisition', $requisition->id, 'enterprise.requisition.created', [
                'requisition_id' => $requisition->id,
                'employee_id' => $requisition->employee_id,
                'status' => 'draft',
            ]);

            return $requisition->load('lines');
        });
    }

    public function submit(string $tenantId, string $requesterId, string $requisitionId): Requisition
    {
        return DB::transaction(function () use ($tenantId, $requesterId, $requisitionId) {
            $requisition = Requisition::forTenant($tenantId)->lockForUpdate()->findOrFail($requisitionId);

            if ($requisition->status !== 'draft') {
                throw new DomainException('Only a draft requisition can be submitted.');
            }

            $requisition->update(['status' => 'submitted']);

            $this->approvalEngine->createApproval(
                $tenantId,
                $requesterId,
                'procurement',
                'requisition',
                $requisition->id,
                'Requisition submitted for procurement approval',
                ['requisition_id' => $requisition->id],
            );

            $this->eventStore->append($tenantId, 'requisition', $requisition->id, 'enterprise.requisition.submitted', [
                'requisition_id' => $requisition->id,
                'status' => 'submitted',
            ]);

            return $requisition->refresh()->load('lines');
        });
    }

    /**
     * Approval grants procurement. It does not create a purchase order.
     */
    public function onApprovalResolved(string $tenantId, string $requisitionId, bool $granted, string $response = ''): void
    {
        $requisition = Requisition::forTenant($tenantId)->lockForUpdate()->findOrFail($requisitionId);

        if ($requisition->status !== 'submitted') {
            throw new DomainException('Requisition is not awaiting approval.');
        }

        $status = $granted ? 'approved' : 'rejected';
        $requisition->update(['status' => $status]);

        $this->eventStore->append(
            $tenantId,
            'requisition',
            $requisition->id,
            $granted ? 'enterprise.requisition.approved' : 'enterprise.requisition.rejected',
            [
                'requisition_id' => $requisition->id,
                'status' => $status,
            ],
        );
    }
}
