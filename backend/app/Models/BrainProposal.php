<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed change to a brain path. Agents (and "remember this" in Atlas)
 * never write the shared brain directly: the proposal is routed through
 * ApprovalEngine (resource type `brain_proposal`) and applied by
 * BrainProposalService against `base_version` (409 when stale).
 */
class BrainProposal extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_APPLIED, self::STATUS_SUPERSEDED,
    ];

    public const OPEN = [self::STATUS_PENDING, self::STATUS_APPROVED];

    public const BY_AGENT = 'agent';

    public const BY_USER = 'user';

    public const BY_SYSTEM = 'system';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'proposed_by_type' => self::BY_AGENT,
    ];

    protected $fillable = [
        'tenant_id', 'path', 'brain_file_id', 'base_version', 'proposed_content', 'proposed_frontmatter',
        'rationale', 'status', 'approval_id', 'proposed_by_type', 'proposed_by_ref', 'agent_run_id',
        'applied_version', 'resolved_at',
    ];

    protected $casts = [
        'proposed_frontmatter' => 'array',
        'base_version' => 'integer',
        'applied_version' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<BrainFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(BrainFile::class, 'brain_file_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
