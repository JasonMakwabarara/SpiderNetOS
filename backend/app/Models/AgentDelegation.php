<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDelegation extends Model
{
    protected $fillable = [
        'agent_id',
        'delegate_id',
        'permission',
        'conditions',
    ];

    protected $casts = [
        'conditions' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'delegate_id');
    }
}
