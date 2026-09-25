<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the founder actually reads: the table, the consensus, the dissent, one action. */
class BoardVerdict extends Model
{
    use HasUuids;

    protected $table = 'board_verdicts';

    protected $fillable = [
        'tenant_id', 'session_id', 'consensus', 'table_rows', 'minority_report', 'minority_seat',
        'recommended_action', 'next_check_date', 'kill_criteria', 'approval_id', 'meta',
    ];

    protected $casts = [
        'table_rows' => 'array',
        'kill_criteria' => 'array',
        'meta' => 'array',
        'next_check_date' => 'date',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BoardSession::class, 'session_id');
    }
}
