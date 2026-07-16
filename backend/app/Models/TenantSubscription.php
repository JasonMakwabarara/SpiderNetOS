<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's current platform-plan subscription. At most one live row per
 * tenant (partial unique index on status in trialing|active|past_due).
 */
class TenantSubscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'plan_id', 'dodo_subscription_id', 'dodo_customer_id',
        'status', 'trial_ends_at', 'current_period_start', 'current_period_end',
        'cancel_at_period_end', 'cancelled_at', 'meta',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'meta' => 'array',
    ];

    /** Statuses that grant plan entitlements (a live subscription). */
    public const LIVE_STATUSES = ['trialing', 'active', 'past_due'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function inTrial(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }
}
