<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sop extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'tenant_id', 'process_id', 'version', 'title', 'purpose', 'trigger',
        'tools', 'steps', 'quality_criteria', 'status', 'created_by', 'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'tools' => 'array',
        'steps' => 'array',
        'quality_criteria' => 'array',
        'notes' => 'array',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }
}
