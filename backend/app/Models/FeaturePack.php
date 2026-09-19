<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $pack_id
 * @property string $version
 * @property string $vertical
 * @property string $display_name
 * @property string|null $description
 * @property array|null $manifest
 * @property string $status
 * @property Carbon|null $installed_at
 * @property-read array $agents
 * @property-read array $flows
 */
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
