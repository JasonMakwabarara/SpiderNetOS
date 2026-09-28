<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One question put to the board (plan D6 §6). */
class BoardSession extends Model
{
    use HasUuids;

    public const STATUSES = ['open', 'round_1', 'round_2', 'synthesis', 'complete', 'failed', 'cancelled'];

    protected $table = 'board_sessions';

    protected $attributes = ['status' => 'open'];

    protected $fillable = [
        'tenant_id', 'opened_by', 'slug', 'question', 'brief', 'seats',
        'status', 'round', 'cost_usd', 'tokens', 'brain_path', 'error', 'meta', 'completed_at',
    ];

    protected $casts = [
        'brief' => 'array',
        'seats' => 'array',
        'meta' => 'array',
        'round' => 'integer',
        'tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'completed_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function takes(): HasMany
    {
        return $this->hasMany(BoardTake::class, 'session_id')->orderBy('round')->orderBy('seat');
    }

    public function verdict(): HasOne
    {
        return $this->hasOne(BoardVerdict::class, 'session_id');
    }
}
