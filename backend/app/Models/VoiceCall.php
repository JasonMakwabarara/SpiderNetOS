<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VoiceCall extends Model
{
    use HasFactory;

    protected $table = 'voice_calls';

    protected $fillable = [
        'tenant_id',
        'call_sid',
        'phone_number',
        'from_number',
        'direction',
        'status',
        'duration_seconds',
        'transcript',
        'summary',
        'actions_taken',
        'metadata',
        'cost_estimate',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'transcript' => 'array',
        'actions_taken' => 'array',
        'metadata' => 'array',
        'cost_estimate' => 'decimal:4',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->created_at = $model->created_at ?? now();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function summaryRecord(): HasOne
    {
        return $this->hasOne(VoiceCallSummary::class);
    }
}
