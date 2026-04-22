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
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
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
