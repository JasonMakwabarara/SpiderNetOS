<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DagNode extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'flow_id',
        'node_type',
        'agent_id',
        'config',
        'position_x',
        'position_y',
    ];
    
    protected $casts = [
        'config' => 'array',
    ];
    
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
    
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
    
    public function outgoingEdges()
    {
        return $this->hasMany(DagEdge::class, 'source_node_id');
    }
    
    public function incomingEdges()
    {
        return $this->hasMany(DagEdge::class, 'target_node_id');
    }
}
