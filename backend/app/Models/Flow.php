<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flow extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'dag',
        'triggers',
        'status',
        'published_at',
    ];

    protected $casts = [
        'dag' => 'array',
        'triggers' => 'array',
        'published_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(FlowExecution::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(DagNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(DagEdge::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
