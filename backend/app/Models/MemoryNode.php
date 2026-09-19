<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryNode extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'node_type',
        'content',
        'embedding',
        'metadata',
        'recency_score',
        'importance_score',
        'access_count',
        'last_accessed_at',
    ];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
        'last_accessed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function outgoingEdges()
    {
        return $this->hasMany(MemoryEdge::class, 'source_id');
    }

    public function incomingEdges()
    {
        return $this->hasMany(MemoryEdge::class, 'target_id');
    }

    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
        $this->updateRecencyScore();
    }

    private function updateRecencyScore(): void
    {
        // Decay-based recency: score = 1 / (1 + hours_since_access)
        $hours = $this->last_accessed_at ? now()->diffInHours($this->last_accessed_at) : 9999;
        $this->recency_score = 1 / (1 + $hours);
        $this->save();
    }
}
