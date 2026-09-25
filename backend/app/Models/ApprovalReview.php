<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How an approval was actually reviewed (plan D8 #8): dwell time, whether
 * the diff was opened, whether the body was edited, and the decision. The
 * promotion gate counts real reviews, not clicks.
 */
class ApprovalReview extends Model
{
    use HasUuids;

    public const DECISIONS = ['approved', 'rejected', 'edited', 'deferred', 'viewed'];

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'approval_id', 'user_id', 'dwell_ms', 'diff_expanded', 'edited', 'decision', 'meta', 'created_at',
    ];

    protected $casts = [
        'dwell_ms' => 'integer',
        'diff_expanded' => 'boolean',
        'edited' => 'boolean',
        'meta' => 'array',
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

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
