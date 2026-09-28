<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentArtifact;
use App\Services\Tools\Drafts\DraftsSubmitForReviewTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The artifacts one review covers, and the one way to lock them.
 *
 * Every writer that touches a bundle under review — the decision, the
 * approval hook, an edit, an apply — locks its rows here, in id order, and
 * only then touches the approval row. One order for all of them, so two
 * writers queue rather than deadlock.
 */
final class ReviewBundle
{
    /**
     * The artifact the review is about: the approval's resource when there is
     * one, the owning sequence for a step email, otherwise the artifact.
     */
    public static function primaryOf(AgentArtifact $artifact): AgentArtifact
    {
        $tenant = (string) $artifact->tenant_id;
        if ($artifact->approval_id !== null) {
            $resourceId = DB::table('approvals')->where('id', $artifact->approval_id)->where('tenant_id', $tenant)->value('resource_id');
            $primary = $resourceId !== null ? AgentArtifact::forTenant($tenant)->find($resourceId) : null;
            if ($primary !== null) {
                return $primary;
            }
        }
        $sequenceId = (string) (((array) $artifact->meta)['sequence_id'] ?? '');
        if ($sequenceId !== '') {
            return AgentArtifact::forTenant($tenant)->find($sequenceId) ?? $artifact;
        }

        return $artifact;
    }

    /**
     * Lock every artifact the primary's review covers, in id order, and
     * return the locked rows. The ids come from an unlocked read; the rows
     * returned — and any status decided on — come from the lock.
     *
     * @return Collection<int, AgentArtifact>
     */
    public static function lock(AgentArtifact $primary): Collection
    {
        $tenant = (string) $primary->tenant_id;
        $ids = DraftsSubmitForReviewTool::bundle($primary)->pluck('id');
        if ($primary->approval_id !== null) {
            $ids = $ids->merge(AgentArtifact::forTenant($tenant)->where('approval_id', $primary->approval_id)->pluck('id'));
        }

        return AgentArtifact::forTenant($tenant)
            ->whereIn('id', $ids->unique()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
