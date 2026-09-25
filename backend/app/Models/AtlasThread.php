<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A durable Atlas conversation thread (plan D8 #13): what it is about, the
 * brain paths pinned to it, the runs it spawned, open questions, the last
 * summary/next steps, and the "one more question" novelty state. The
 * cockpit's stores/atlas.js creates and loads these via /api/atlas/sessions.
 */
class AtlasThread extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'user_id', 'title', 'business', 'pinned_brain_paths', 'spawned_run_ids',
        'open_questions', 'last_summary', 'last_next_steps', 'one_more_question_state', 'last_seen_at',
    ];

    protected $casts = [
        'pinned_brain_paths' => 'array',
        'spawned_run_ids' => 'array',
        'open_questions' => 'array',
        'last_next_steps' => 'array',
        'one_more_question_state' => 'array',
        'last_seen_at' => 'datetime',
    ];

    protected $attributes = [
        'pinned_brain_paths' => '[]',
        'spawned_run_ids' => '[]',
        'open_questions' => '[]',
        'last_next_steps' => '[]',
        'one_more_question_state' => '{}',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('last_seen_at')->orderByDesc('created_at');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toEnvelope(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'business' => $this->business,
            'pinned_brain_paths' => array_values((array) $this->pinned_brain_paths),
            'spawned_run_ids' => array_values((array) $this->spawned_run_ids),
            'open_questions' => array_values((array) $this->open_questions),
            'last_summary' => $this->last_summary,
            'last_next_steps' => array_values((array) $this->last_next_steps),
            'one_more_question_state' => (array) $this->one_more_question_state,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
