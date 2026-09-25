<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Draft output of a run, living in the workspace drafts folder. Draft-only
 * writes: nothing here is ever sent or published by the runtime — a
 * submitted artifact becomes an approval (resource type `agent_artifact`)
 * and ArtifactApplier turns an approved one into templates, a sent reply, a
 * booking or a brain write.
 */
class AgentArtifact extends Model
{
    use HasUuids;

    public const KIND_DRAFT_EMAIL = 'draft_email';

    public const KIND_DRAFT_SEQUENCE = 'draft_sequence';

    public const KIND_DRAFT_REPLY = 'draft_reply';

    public const KIND_CAPTION = 'caption';

    public const KIND_REPORT = 'report';

    public const KIND_NOTE = 'note';

    public const KIND_CLASSIFICATION = 'classification';

    public const KIND_MEETING_PROPOSAL = 'meeting_proposal';

    public const KINDS = [
        self::KIND_DRAFT_EMAIL, self::KIND_DRAFT_SEQUENCE, self::KIND_DRAFT_REPLY, self::KIND_CAPTION,
        self::KIND_REPORT, self::KIND_NOTE, self::KIND_CLASSIFICATION, self::KIND_MEETING_PROPOSAL,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_APPLIED,
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'tenant_id', 'run_id', 'workspace_id', 'skill_slug', 'kind', 'path', 'title', 'content', 'meta',
        'status', 'approval_id', 'applied_ref', 'submitted_at', 'approved_at', 'applied_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /** @return BelongsTo<AgentWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(AgentWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_slug', 'slug');
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
