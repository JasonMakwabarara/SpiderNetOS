<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One human correction of an agent-written body (plan D8 #1): the original
 * kept next to what was actually approved/sent, with a normalised edit
 * distance and the categories the edit touched. Written by
 * RevisionRecorder; read by the promotion gate (clean = distance < 0.05)
 * and DistilCorrectionsJob.
 */
class ArtifactRevision extends Model
{
    use HasUuids;

    public const SUBJECT_AGENT_ARTIFACT = 'agent_artifact';

    public const SUBJECT_CONVERSATION_MESSAGE = 'conversation_message';

    public const SUBJECT_TYPES = [self::SUBJECT_AGENT_ARTIFACT, self::SUBJECT_CONVERSATION_MESSAGE];

    public const ACTION_EDIT = 'edit';

    public const ACTION_REJECT = 'reject';

    public const ACTION_RECLASSIFY = 'reclassify';

    public const ACTIONS = [self::ACTION_EDIT, self::ACTION_REJECT, self::ACTION_RECLASSIFY];

    public const CATEGORIES = ['facts', 'links', 'numbers', 'length', 'tone', 'ask'];

    protected $attributes = [
        'action' => self::ACTION_EDIT,
        'distance' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id', 'user_id', 'action', 'skill_slug',
        'original_body', 'edited_body', 'distance', 'categories', 'why', 'meta',
    ];

    protected $casts = [
        'distance' => 'float',
        'categories' => 'array',
        'meta' => 'array',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForSubject($query, string $subjectType, string $subjectId)
    {
        return $query->where('subject_type', $subjectType)->where('subject_id', $subjectId);
    }

    /** Below the 5 % edit-distance threshold (named apart from Eloquent's dirty-tracking isClean()). */
    public function isCleanDraft(): bool
    {
        return (float) $this->distance < \App\Services\Revisions\RevisionRecorder::CLEAN_THRESHOLD;
    }
}
