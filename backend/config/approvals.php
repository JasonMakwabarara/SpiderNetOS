<?php

/**
 * SpiderNet OS — approval resource hooks (plan D3, ADR-0002).
 *
 * When an approval resolves (granted, rejected or expired) ApprovalEngine::
 * fireResourceHook() looks the approval's resource_type up here and calls
 * `app($class)->$method($tenantId, $resourceId, $granted, $response)`.
 * Classes are resolved lazily: an entry whose class has not shipped yet is
 * skipped with a log line instead of an exception. Resource types that are
 * not listed keep their legacy inline hooks inside the engine
 * (sales_script, expense_report, bill, payment).
 *
 * Fired from every resolution path — ApprovalController::approve/reject
 * (single-stage), ApprovalEngine::resolveStep (chains) and
 * expireOverdueSteps — so a hook never depends on how the approval was
 * answered.
 */
return [

    'resource_hooks' => [
        // Recruiter bot draft replies (Outreach). Kept byte-for-byte: the
        // handler receives (tenantId, draftId, granted, response).
        'outreach_reply' => [\App\Services\Outreach\Bot\OutreachReplyService::class, 'onApprovalResolved'],

        // Draft artifacts from skill runs (sequences, emails, replies…):
        // approved → ArtifactApplier, rejected → artifact rejected.
        'agent_artifact' => [\App\Services\Agents\AgentArtifactApprovals::class, 'onApprovalResolved'],

        // A tool call the autonomy ladder parked: resumes the run.
        'agent_tool_call' => [\App\Services\Agents\AgentRunResumer::class, 'onApprovalResolved'],

        // Proposed Knowledge-brain changes (Stream A / PR 3).
        'brain_proposal' => [\App\Services\Brain\BrainProposalService::class, 'onApprovalResolved'],
    ],

];
