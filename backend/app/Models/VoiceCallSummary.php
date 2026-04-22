<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallSummary extends Model
{
    use HasFactory;

    protected $table = 'voice_call_summaries';

    protected $fillable = [
        'voice_call_id',
        'tenant_id',
        'summary',
        'key_points',
        'follow_up_tasks',
        'sentiment',
        'email_sent',
        'processed_at',
    ];

    protected $casts = [
        'key_points' => 'array',
        'follow_up_tasks' => 'array',
        'email_sent' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->created_at = $model->created_at ?? now();
        });
    }

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
