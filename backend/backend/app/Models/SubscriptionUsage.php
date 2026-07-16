<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionUsage extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'subscription_id', 'tenant_id', 'metric_name', 'quantity',
        'period_start', 'period_end',
    ];
    
    protected $casts = [
        'quantity' => 'decimal:4',
    ];
    
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
