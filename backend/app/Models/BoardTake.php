<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat's view at one round. Round 1 takes are kept after anonymisation so
 * a verdict can always be traced back to who actually said what.
 */
class BoardTake extends Model
{
    use HasUuids;

    protected $table = 'board_takes';

    protected $fillable = [
        'tenant_id', 'session_id', 'seat', 'round', 'anon_label', 'verdict', 'raw', 'cost_usd', 'tokens', 'error',
    ];

    protected $casts = [
        'verdict' => 'array',
        'round' => 'integer',
        'tokens' => 'integer',
        'cost_usd' => 'decimal:6',
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
