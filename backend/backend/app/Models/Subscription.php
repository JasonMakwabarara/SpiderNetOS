<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'tenant_id', 'plan_id', 'plan_name', 'provider', 'provider_subscription_id',
        'status', 'amount', 'currency', 'interval', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancelled_at', 'ends_at',
        'metadata',
    ];
    
    protected $casts = [
        'amount' => 'decimal:4',
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function usageRecords(): HasMany
    {
        return $this->hasMany(SubscriptionUsage::class);
    }
}
