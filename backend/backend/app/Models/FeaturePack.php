<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeaturePack extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'pack_id',
        'version',
        'vertical',
        'display_name',
        'description',
        'manifest',
        'status',
        'installed_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'installed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getAgentsAttribute(): array
    {
        return $this->manifest['spec']['provides']['dynamic_agents'] ?? [];
    }

    public function getFlowsAttribute(): array
    {
        return $this->manifest['spec']['provides']['flows'] ?? [];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
