<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace entry for a run: prompt built, model called, tool invoked/denied,
 * validator verdict, approval parked, error. Append-only; ordered by `seq`.
 */
class AgentRunStep extends Model
{
    use HasUuids;

    public const KIND_PROMPT = 'prompt';

    public const KIND_MODEL = 'model';

    public const KIND_TOOL_CALL = 'tool_call';

    public const KIND_TOOL_RESULT = 'tool_result';

    public const KIND_VALIDATOR = 'validator';

    public const KIND_APPROVAL = 'approval';

    public const KIND_NOTE = 'note';

    public const KIND_ERROR = 'error';

    public const KINDS = [
        self::KIND_PROMPT, self::KIND_MODEL, self::KIND_TOOL_CALL, self::KIND_TOOL_RESULT,
        self::KIND_VALIDATOR, self::KIND_APPROVAL, self::KIND_NOTE, self::KIND_ERROR,
    ];

    public const STATUS_OK = 'ok';

    public const STATUS_DENIED = 'denied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public $timestamps = false;

    protected $attributes = [
        'status' => self::STATUS_OK,
        'tokens' => 0,
        'cost_usd' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'run_id', 'seq', 'kind', 'name', 'input', 'output', 'status', 'tokens', 'cost_usd',
        'duration_ms', 'created_at',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'seq' => 'integer',
        'tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = $model->created_at ?? now();
        });
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }
}
