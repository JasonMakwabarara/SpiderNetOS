<?php

use App\Services\Agents\AgentArtifactApprovals;
use App\Services\Agents\AgentRunResumer;
use App\Services\Brain\BrainProposalService;
use App\Services\Launch\BusinessLaunchService;
use App\Services\Outreach\Bot\OutreachReplyService;

/**
 * SpiderNet OS — approval resource hooks (plan D3, ADR-0002).
 *
 * When an approval resolves (granted, rejected or expired) ApprovalEngine::
 * fireResourceHook() looks the approval's resource_type up here and calls
 * `app($class)->$method($tenantId, $resourceId, $granted, $response, $decision)`,
 * where `$decision` names the deciding approval (`approval_id`) and the
 * version it bound (`approved_version_hash`). A handler that does not
 * declare the fifth parameter never sees it. Classes are resolved lazily: an
 * entry whose class has not shipped yet is skipped with a log line instead of
 * an exception. Resource types that are not listed keep their legacy inline
 * hooks inside the engine (sales_script, expense_report, bill, payment).
 *
 * Fired from every resolution path — ApprovalEngine::decideSingleStage (the
 * controller and resolveApproval), ApprovalEngine::resolveStep (chains) and
 * expireOverdueSteps — so a hook never depends on how the approval was
 * answered.
 */
return [

    'resource_hooks' => [
        // Recruiter bot draft replies (Outreach). Kept byte-for-byte: the
        // handler receives (tenantId, draftId, granted, response).
        'outreach_reply' => [OutreachReplyService::class, 'onApprovalResolved'],

        // Draft artifacts from skill runs (sequences, emails, replies…):
        // approved → ArtifactApplier, rejected → artifact rejected.
        'agent_artifact' => [AgentArtifactApprovals::class, 'onApprovalResolved'],

        // A tool call the autonomy ladder parked: resumes the run.
        'agent_tool_call' => [AgentRunResumer::class, 'onApprovalResolved'],

        // Proposed Knowledge-brain changes (Stream A / PR 3).
        'brain_proposal' => [BrainProposalService::class, 'onApprovalResolved'],

        // The business plan the business-launch pack drafts (plan D7 §5):
        // approved -> the launch goes live, rejected -> back to `drafted`
        // so the founder can revise and resubmit.
        'business_plan' => [BusinessLaunchService::class, 'onApprovalResolved'],
    ],

    /*
     * Resource types whose approval binds the exact version the approver
     * saw. `[$class, $method]` is called as `$method($tenantId, $approval)`
     * inside the decision's transaction; it must lock the resource and return
     * the canonical hash of what approving it would apply now. A grant must
     * then present that hash (ApprovalEngine::decideSingleStage), and the hook
     * applies only that version. An approval of such a type that was never
     * bound is refused, not applied unbound.
     */
    'version_bindings' => [
        'agent_artifact' => [AgentArtifactApprovals::class, 'currentVersion'],
    ],

];
