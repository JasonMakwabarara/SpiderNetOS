<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One issue of either newsletter (plan D8 #15, #16).
 *
 * The C-Suite issue is internal: one recipient, no list, no third party — it
 * rides with the Monday letter. The customer issue is public and therefore
 * always draft-only until an approval says otherwise; nothing in this model
 * sends anything.
 */
class NewsletterIssue extends Model
{
    use HasUuids;

    public const KIND_CSUITE = 'csuite';

    public const KIND_CUSTOMER = 'customer';

    public const KINDS = [self::KIND_CSUITE, self::KIND_CUSTOMER];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED,
        self::STATUS_SENT, self::STATUS_SKIPPED, self::STATUS_FAILED,
    ];

    public const CHANNEL_COCKPIT = 'cockpit_only';

    public const CHANNEL_TENANT_MAILBOX = 'tenant_mailbox';

    public const CHANNEL_BEEHIIV = 'beehiiv';

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'tenant_id', 'kind', 'period', 'status', 'subject', 'preheader', 'markdown', 'html',
        'quote_id', 'brain_path', 'approval_id', 'agent_run_id', 'channel', 'external_id',
        'recipients', 'stats', 'meta', 'scheduled_for', 'sent_at',
    ];

    protected $casts = [
        'stats' => 'array',
        'meta' => 'array',
        'recipients' => 'integer',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    /** ISO week key the C-Suite issue is filed under, e.g. "2026-W38". */
    public static function weekKey(\DateTimeInterface $date): string
    {
        return Carbon::parse($date)->format('o-\WW');
    }
}
