<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VoiceQuota — Phase D
 *
 * Monthly usage caps and accumulators per tenant.
 * Uses tenant_id as primary key (one row per tenant).
 *
 * @property string $tenant_id
 * @property int $monthly_minutes_cap
 * @property int $monthly_minutes_used
 * @property int $outbound_cap
 * @property int $outbound_used
 * @property int $sms_cap
 * @property int $sms_used
 * @property string|null $reset_at
 */
class VoiceQuota extends Model
{
    protected $table = 'voice_quotas';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'monthly_minutes_cap',
        'monthly_minutes_used',
        'outbound_cap',
        'outbound_used',
        'sms_cap',
        'sms_used',
        'reset_at',
    ];

    protected $casts = [
        'monthly_minutes_cap' => 'integer',
        'monthly_minutes_used' => 'integer',
        'outbound_cap' => 'integer',
        'outbound_used' => 'integer',
        'sms_cap' => 'integer',
        'sms_used' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    // ─── Business logic ───────────────────────────────────────────────────────

    public function canMakeCall(): bool
    {
        if ($this->outbound_cap === 0) {
            return true; // 0 = unlimited
        }

        return $this->outbound_used < $this->outbound_cap;
    }

    public function hasMinutesRemaining(int $estimatedMinutes = 1): bool
    {
        if ($this->monthly_minutes_cap === 0) {
            return true; // 0 = unlimited
        }

        return ($this->monthly_minutes_used + $estimatedMinutes) <= $this->monthly_minutes_cap;
    }

    public function canSendSms(): bool
    {
        if ($this->sms_cap === 0) {
            return true;
        }

        return $this->sms_used < $this->sms_cap;
    }

    /**
     * Atomically increment outbound usage counter.
     */
    public function incrementOutbound(): void
    {
        $this->increment('outbound_used');
    }

    /**
     * Atomically increment SMS usage counter.
     */
    public function incrementSms(int $segments = 1): void
    {
        $this->increment('sms_used', $segments);
    }

    /**
     * Add minutes to the monthly accumulator.
     */
    public function addMinutes(int $minutes): void
    {
        $this->increment('monthly_minutes_used', $minutes);
    }
}
