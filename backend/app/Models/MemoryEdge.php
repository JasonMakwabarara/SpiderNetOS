<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryEdge extends Model
{
    protected $fillable = [
        'source_id',
        'target_id',
        'relation_type',
        'weight',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'source_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'target_id');
    }
}
