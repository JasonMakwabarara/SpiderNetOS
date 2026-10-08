<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function preview(string $tenantId, string $series, string $prefix, int $pad = 6): string
    {
        $last = DocumentSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('series', $series)
            ->value('last_number');

        return $this->format($prefix, ((int) $last) + 1, $pad);
    }

    public function next(string $tenantId, string $series, string $prefix, int $pad = 6): string
    {
        return DB::transaction(function () use ($tenantId, $series, $prefix, $pad) {
            $row = DocumentSequence::query()
                ->where('tenant_id', $tenantId)
                ->where('series', $series)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                try {
                    $row = DocumentSequence::create([
                        'tenant_id' => $tenantId,
                        'series' => $series,
                        'prefix' => $prefix,
                        'last_number' => 0,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $row = DocumentSequence::query()
                        ->where('tenant_id', $tenantId)
                        ->where('series', $series)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            $next = (int) $row->last_number + 1;
            $row->update(['last_number' => $next, 'prefix' => $prefix]);

            return $this->format($prefix, $next, $pad);
        });
    }

    private function format(string $prefix, int $number, int $pad): string
    {
        return $prefix.str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    }
}
