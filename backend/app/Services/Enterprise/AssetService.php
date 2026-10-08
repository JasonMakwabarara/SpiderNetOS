<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\DepreciationEntry;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Services\EventStore;
use App\Services\Financial\LedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AssetService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly LedgerService $ledger,
    ) {}

    public function create(string $tenantId, array $data): Asset
    {
        try {
            $asset = DB::transaction(function () use ($tenantId, $data) {
                $basis = $this->basis($data);

                return Asset::create([
                    'tenant_id' => $tenantId,
                    'tag' => $data['tag'],
                    'name' => $data['name'],
                    'status' => 'available',
                    ...$basis,
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

    public function markRepair(string $tenantId, string $assetId): Asset
    {
        return $this->transition($tenantId, $assetId, 'available', 'in_repair', 'enterprise.asset.repair');
    }

    public function restore(string $tenantId, string $assetId): Asset
    {
        return $this->transition($tenantId, $assetId, 'in_repair', 'available', 'enterprise.asset.restored');
    }

    public function dispose(string $tenantId, string $assetId): Asset
    {
        return DB::transaction(function () use ($tenantId, $assetId) {
            $asset = Asset::forTenant($tenantId)->lockForUpdate()->findOrFail($assetId);
            if ($asset->status === 'assigned') {
                throw new DomainException('Return the asset before disposing of it.');
            }
            if ($asset->status !== 'available') {
                throw new DomainException('Only an available asset can be disposed.');
            }
            $asset->update(['status' => 'disposed']);
            $this->eventStore->append($tenantId, 'asset', $asset->id, 'enterprise.asset.disposed', [
                'asset_id' => $asset->id,
                'status' => 'disposed',
            ]);

            return $asset->refresh();
        });
    }

    public function depreciate(string $tenantId, string $assetId, string $period): DepreciationEntry
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            throw new DomainException('Depreciation period must be YYYY-MM.');
        }

        return DB::transaction(function () use ($tenantId, $assetId, $period) {
            $asset = Asset::forTenant($tenantId)->lockForUpdate()->findOrFail($assetId);
            if ($asset->status === 'disposed') {
                throw new DomainException('A disposed asset cannot be depreciated.');
            }
            if ($asset->cost === null || $asset->residual_value === null || ! $asset->useful_life_months) {
                throw new DomainException('This asset has no depreciation basis.');
            }
            if (DepreciationEntry::forTenant($tenantId)->where('asset_id', $asset->id)->where('period', $period)->lockForUpdate()->exists()) {
                throw new DomainException('This asset is already depreciated for that period.');
            }

            $base = bcsub((string) $asset->cost, (string) $asset->residual_value, 4);
            $taken = '0.0000';
            foreach (DepreciationEntry::forTenant($tenantId)->where('asset_id', $asset->id)->lockForUpdate()->get() as $row) {
                $taken = bcadd($taken, (string) $row->amount, 4);
            }
            $remaining = bcsub($base, $taken, 4);
            if (bccomp($remaining, '0', 4) !== 1) {
                throw new DomainException('This asset is fully depreciated.');
            }
            $monthly = bcdiv($base, (string) $asset->useful_life_months, 4);
            $amount = bccomp($monthly, $remaining, 4) === 1 ? $remaining : $monthly;

            $entry = DepreciationEntry::create([
                'tenant_id' => $tenantId,
                'asset_id' => $asset->id,
                'period' => $period,
                'amount' => $amount,
                'status' => 'posted',
            ]);
            $accumulated = $this->account($tenantId, 'AST-ACCUM-DEP', 'Accumulated depreciation', 'asset');
            $expense = $this->account($tenantId, 'EXP-DEPRECIATION', 'Depreciation', 'expense');
            $this->ledger->createJournalEntry(
                $tenantId,
                $accumulated->id,
                $expense->id,
                $amount,
                'USD',
                'Asset depreciation',
                'depreciation',
                $entry->id,
            );
            $this->eventStore->append($tenantId, 'depreciation_entry', $entry->id, 'enterprise.asset.depreciated', [
                'depreciation_entry_id' => $entry->id,
                'asset_id' => $asset->id,
                'status' => 'posted',
            ]);

            return $entry;
        });
    }

    private function transition(string $tenantId, string $assetId, string $from, string $to, string $eventType): Asset
    {
        return DB::transaction(function () use ($tenantId, $assetId, $from, $to, $eventType) {
            $asset = Asset::forTenant($tenantId)->lockForUpdate()->findOrFail($assetId);
            if ($asset->status !== $from) {
                throw new DomainException('The asset is not in the required state for this change.');
            }
            $asset->update(['status' => $to]);
            $this->eventStore->append($tenantId, 'asset', $asset->id, $eventType, [
                'asset_id' => $asset->id,
                'status' => $to,
            ]);

            return $asset->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function basis(array $data): array
    {
        $present = isset($data['cost']) || isset($data['residual_value']) || isset($data['useful_life_months']) || isset($data['acquired_on']);
        if (! $present) {
            return [];
        }
        if (! isset($data['cost'], $data['residual_value'], $data['useful_life_months'], $data['acquired_on'])) {
            throw new DomainException('A depreciation basis needs cost, residual value, useful life, and the date acquired.');
        }
        $cost = Money::positive($data['cost']);
        $residual = number_format((float) $data['residual_value'], 4, '.', '');
        if ((float) $residual < 0 || bccomp($residual, $cost, 4) !== -1) {
            throw new DomainException('Residual value must be zero or more and less than cost.');
        }
        $life = (int) $data['useful_life_months'];
        if ($life < 1) {
            throw new DomainException('Useful life must be at least one month.');
        }

        return [
            'acquired_on' => $data['acquired_on'],
            'cost' => $cost,
            'residual_value' => $residual,
            'useful_life_months' => $life,
            'depreciation_method' => 'straight_line',
        ];
    }

    private function account(string $tenantId, string $number, string $name, string $type): FinancialAccount
    {
        return FinancialAccount::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_number' => $number],
            ['name' => $name, 'type' => $type, 'currency' => 'USD', 'status' => 'active'],
        );
    }
}
