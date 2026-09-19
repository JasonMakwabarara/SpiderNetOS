<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agent extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'type',
        'status',
        'capabilities',
        'config',
        'activated_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'config' => 'array',
        'activated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? []);
    }

    public function delegations()
    {
        return $this->hasMany(AgentDelegation::class, 'agent_id');
    }

    public function delegates()
    {
        return $this->belongsToMany(
            Agent::class,
            'agent_delegations',
            'agent_id',
            'delegate_id'
        );
    }
}
