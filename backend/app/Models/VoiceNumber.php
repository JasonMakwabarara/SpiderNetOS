<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceNumber extends Model
{
    use HasFactory;

    protected $table = 'voice_numbers';

    protected $fillable = [
        'tenant_id',
        'phone_number',
        'provider',
        'provider_sid',
        'agent_id',
        'config',
        'is_active',
        // Safety/policy columns (add_voice_agent_policy migration) — absent
        // from fillable they were silently dropped on update, so operators'
        // approval-policy changes never persisted.
        'tool_allowlist',
        'approval_policy',
        'agent_config',
        'allow_outbound',
        'daily_call_cap',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'tool_allowlist' => 'array',
        'agent_config' => 'array',
        'allow_outbound' => 'boolean',
        'daily_call_cap' => 'integer',
    ];

    public $timestamps = true;

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(VoiceCall::class, 'phone_number', 'phone_number');
    }
}
