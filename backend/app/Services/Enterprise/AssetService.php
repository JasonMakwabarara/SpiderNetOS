<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Services\EventStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AssetService
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function create(string $tenantId, array $data): Asset
    {
        try {
            $asset = DB::transaction(function () use ($tenantId, $data) {
                return Asset::create([
                    'tenant_id' => $tenantId,
                    'tag' => $data['tag'],
                    'name' => $data['name'],
                    'status' => 'available',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('Asset tag already exists for this tenant.');
        }

        $this->eventStore->append($tenantId, 'asset', $asset->id, 'enterprise.asset.created', [
            'asset_id' => $asset->id,
            'status' => 'available',
        ]);

        return $asset;
    }

    public function assign(string $tenantId, string $assetId, string $employeeId): Asset
    {
        return DB::transaction(function () use ($tenantId, $assetId, $employeeId) {
            $asset = Asset::forTenant($tenantId)->lockForUpdate()->findOrFail($assetId);
            $employee = Employee::forTenant($tenantId)->findOrFail($employeeId);

            if ($employee->status !== 'active') {
                throw new DomainException('An inactive employee cannot receive a new asset assignment.');
            }

            if ($asset->status !== 'available') {
                throw new DomainException('Only an available asset can be assigned.');
            }

            AssetAssignment::create([
                'tenant_id' => $tenantId,
                'asset_id' => $asset->id,
                'employee_id' => $employeeId,
                'assigned_at' => now(),
            ]);

            $asset->update(['status' => 'assigned']);

            $this->eventStore->append($tenantId, 'asset', $asset->id, 'enterprise.asset.assigned', [
                'asset_id' => $asset->id,
                'employee_id' => $employeeId,
                'status' => 'assigned',
            ]);

            return $asset->refresh();
        });
    }

    public function returnAsset(string $tenantId, string $assetId): Asset
    {
        return DB::transaction(function () use ($tenantId, $assetId) {
            $asset = Asset::forTenant($tenantId)->lockForUpdate()->findOrFail($assetId);

            $assignment = AssetAssignment::forTenant($tenantId)
                ->where('asset_id', $asset->id)
                ->whereNull('returned_at')
                ->lockForUpdate()
                ->first();

            if (! $assignment || $asset->status !== 'assigned') {
                throw new DomainException('Only an assigned asset can be returned.');
            }

            $assignment->update(['returned_at' => now()]);
            $asset->update(['status' => 'available']);

            $this->eventStore->append($tenantId, 'asset', $asset->id, 'enterprise.asset.returned', [
                'asset_id' => $asset->id,
                'employee_id' => $assignment->employee_id,
                'status' => 'available',
            ]);

            return $asset->refresh();
        });
    }
}
