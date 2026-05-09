<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'tenant_id', 'name', 'category', 'amount', 'spent', 'currency',
        'period', 'period_start', 'period_end', 'alert_threshold', 'status',
        'metadata',
    ];
    
    protected $casts = [
        'amount' => 'decimal:4',
        'spent' => 'decimal:4',
        'alert_threshold' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
        'metadata' => 'array',
    ];
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
    
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
    
    public function isOverBudget(): bool
    {
        return $this->spent >= $this->amount;
    }
    
    public function getUtilizationPercentage(): float
    {
        if ($this->amount == 0) return 0;
        return ($this->spent / $this->amount) * 100;
    }
}
