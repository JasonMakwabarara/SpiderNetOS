<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'conversation_id', 'direction', 'body', 'template_key',
        'provider_message_id', 'status', 'error', 'sent_by',
        // Email threading + draft bookkeeping (partner outreach).
        'subject', 'message_id_header', 'in_reply_to', 'references_header', 'headers',
        'classification', 'approval_id', 'draft_action', 'draft_meta', 'sent_at',
    ];

    protected $casts = [
        'headers' => 'array',
        'draft_meta' => 'array',
        'sent_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
