<?php

declare(strict_types=1);

namespace App\Services\Financial;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Race-free document numbering. Callers must already be inside a
| DB::transaction — the sequence row is locked FOR UPDATE, so two
| concurrent allocations for the same (tenant, type) serialize
| instead of colliding on the unique number column.
*/
class DocumentNumberService
{
    public function next(string $tenantId, string $type, string $prefix): string
    {
        $number = $this->allocate($tenantId, $type);

        return sprintf('%s-%s-%06d', $prefix, date('Ymd'), $number);
    }

    private function allocate(string $tenantId, string $type): int
    {
        $row = DB::table('document_sequences')
            ->where('tenant_id', $tenantId)
            ->where('sequence_type', $type)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            DB::table('document_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'sequence_type' => $type,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Re-read with the lock: if a concurrent insert won, we lock its row.
            $row = DB::table('document_sequences')
                ->where('tenant_id', $tenantId)
                ->where('sequence_type', $type)
                ->lockForUpdate()
                ->first();
        }

        $number = (int) $row->next_number;

        DB::table('document_sequences')
            ->where('id', $row->id)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return $number;
    }
}
