<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One founder journey through the business-launch pack (plan D7 §5):
 *
 *   purchased → interviewing → researching → modelling → drafted
 *             → awaiting_approval → approved → live
 *
 * Mirrors 2026_09_22_000003_create_business_launches. `interview_answers` is
 * keyed by interview question id (packages/feature-packs/business-launch/
 * interview/questions.yaml); `stage_artifacts` records each stage commit
 * (brain paths → versions) and every generated deliverable.
 *
 * Statuses only ever move forwards (STATUS_ORDER) — a rejected plan sends the
 * launch back to `drafted`, never behind the answers already given.
 */
class BusinessLaunch extends Model
{
    use HasUuids;

    public const STATUS_PURCHASED = 'purchased';

    public const STATUS_INTERVIEWING = 'interviewing';

    public const STATUS_RESEARCHING = 'researching';

    public const STATUS_MODELLING = 'modelling';

    public const STATUS_DRAFTED = 'drafted';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_LIVE = 'live';

    /** Ordered — index in this list is the rank used by `atLeast()`. */
    public const STATUS_ORDER = [
        self::STATUS_PURCHASED,
        self::STATUS_INTERVIEWING,
        self::STATUS_RESEARCHING,
        self::STATUS_MODELLING,
        self::STATUS_DRAFTED,
        self::STATUS_AWAITING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_LIVE,
    ];

    public const STATUSES = self::STATUS_ORDER;

    protected $fillable = [
        'tenant_id', 'pack_id', 'status', 'current_stage', 'interview_answers',
        'stage_artifacts', 'jurisdiction', 'approval_id', 'went_live_at',
    ];

    protected $casts = [
        'interview_answers' => 'array',
        'stage_artifacts' => 'array',
        'went_live_at' => 'datetime',
    ];

    protected $attributes = [
        'pack_id' => 'business-launch',
        'status' => self::STATUS_PURCHASED,
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Rank of a status in the chain; -1 for anything unknown. */
    public static function rank(?string $status): int
    {
        $index = array_search((string) $status, self::STATUS_ORDER, true);

        return $index === false ? -1 : (int) $index;
    }

    /** True when the launch has reached (or passed) $status. */
    public function atLeast(string $status): bool
    {
        return self::rank($this->status) >= self::rank($status);
    }

    /**
     * Answers as a flat map of question id => answer text, dropping skips.
     * The stored shape is {question, answer, answered_at, skipped?}.
     *
     * @return array<string, string>
     */
    public function answerValues(): array
    {
        $out = [];
        foreach ((array) ($this->interview_answers ?? []) as $questionId => $entry) {
            if (is_array($entry)) {
                if (! empty($entry['skipped'])) {
                    continue;
                }
                $value = (string) ($entry['answer'] ?? '');
            } else {
                $value = (string) $entry;
            }
            if ($value !== '') {
                $out[(string) $questionId] = $value;
            }
        }

        return $out;
    }
}
