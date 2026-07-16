<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAlert extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'tenant_id', 'type', 'severity', 'title', 'message',
        'context', 'status', 'user_id', 'acknowledged_at',
    ];
    
    protected $casts = [
        'context' => 'array',
        'acknowledged_at' => 'datetime',
    ];
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
    
    public function scopeUnread($query)
    {
        return $query->where('status', 'unread');
    }
    
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }
    
    public function markAcknowledged(): void
    {
        $this->status = 'acknowledged';
        $this->acknowledged_at = now();
        $this->save();
    }
}
