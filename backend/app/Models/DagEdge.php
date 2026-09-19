<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DagEdge extends Model
{
    use HasUuids;

    protected $fillable = [
        'flow_id',
        'source_node_id',
        'target_node_id',
        'condition',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DagNode::class, 'source_node_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(DagNode::class, 'target_node_id');
    }
}
