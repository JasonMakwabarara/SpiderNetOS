<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A creator / page owner being recruited into a tenant's affiliate program.
 * 1:1 with a Lead (channels, consent and conversations hang off the lead);
 * this row owns the outreach lifecycle.
 */
class PartnerProspect extends Model
{
    use HasUuids;

    // Not yet contactable.
    public const STATUS_NEW = 'new';

    public const STATUS_NEEDS_EMAIL = 'needs_email';

    // Email sequence.
    public const STATUS_READY = 'ready';

    public const STATUS_INVITED = 'invited';

    public const STATUS_NUDGED = 'nudged';

    public const STATUS_LAST_CALLED = 'last_called';

    public const STATUS_RETIRED = 'retired';

    // Operator-assisted DMs.
    public const STATUS_DM_DRAFTED = 'dm_drafted';

    public const STATUS_DM_SENT = 'dm_sent';

    // Conversation.
    public const STATUS_REPLIED = 'replied';

    public const STATUS_NEGOTIATING = 'negotiating';

    public const STATUS_HANDOFF = 'handoff';

    // Terminal.
    public const STATUS_SIGNED_UP = 'signed_up';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUSES = [
        self::STATUS_NEW, self::STATUS_NEEDS_EMAIL, self::STATUS_READY, self::STATUS_INVITED,
        self::STATUS_NUDGED, self::STATUS_LAST_CALLED, self::STATUS_RETIRED, self::STATUS_DM_DRAFTED,
        self::STATUS_DM_SENT, self::STATUS_REPLIED, self::STATUS_NEGOTIATING, self::STATUS_HANDOFF,
        self::STATUS_SIGNED_UP, self::STATUS_DECLINED, self::STATUS_UNSUBSCRIBED, self::STATUS_BOUNCED,
    ];

    public const TERMINAL = [
        self::STATUS_SIGNED_UP, self::STATUS_DECLINED, self::STATUS_UNSUBSCRIBED, self::STATUS_BOUNCED, self::STATUS_RETIRED,
    ];

    /** Statuses the email sequence may send from. */
    public const SENDABLE = [self::STATUS_READY, self::STATUS_INVITED, self::STATUS_NUDGED];

    /** Statuses that mean "we have been in touch" (a reply may arrive). */
    public const CONTACTED = [
        self::STATUS_INVITED, self::STATUS_NUDGED, self::STATUS_LAST_CALLED, self::STATUS_RETIRED, self::STATUS_DM_SENT,
    ];

    protected $fillable = [
        'tenant_id', 'lead_id', 'platform', 'handle', 'profile_url', 'profile_url_hash',
        'primary_content_url', 'all_urls', 'display_name', 'source', 'source_meta',
        'affonso_shortlist_item_id', 'affonso_affiliate_id', 'affonso_tracking_id', 'affiliate_status',
        'invite_token', 'status', 'email_source', 'email_verified_at', 'sequence_step', 'next_send_at',
        'last_sent_at', 'last_inbound_at', 'replied_at', 'signed_up_at', 'declined_at', 'unsubscribed_at',
        'bounced_at', 'bounce_reason', 'needs_human_at', 'needs_human_reason', 'bot_paused_at',
        'dm_draft_at', 'dm_sent_at', 'claimed_at', 'claim_token', 'reply_claim_message_id',
        'bot_replies_today', 'bot_replies_day', 'notes',
    ];

    protected $casts = [
        'all_urls' => 'array',
        'source_meta' => 'array',
        'sequence_step' => 'integer',
        'bot_replies_today' => 'integer',
        'bot_replies_day' => 'date',
        'email_verified_at' => 'datetime',
        'next_send_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'replied_at' => 'datetime',
        'signed_up_at' => 'datetime',
        'declined_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'bounced_at' => 'datetime',
        'needs_human_at' => 'datetime',
        'bot_paused_at' => 'datetime',
        'dm_draft_at' => 'datetime',
        'dm_sent_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function hasBeenContacted(): bool
    {
        return in_array($this->status, self::CONTACTED, true) || $this->last_sent_at !== null || $this->dm_sent_at !== null;
    }
}
