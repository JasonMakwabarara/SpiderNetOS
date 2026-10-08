<?php

use App\Services\Agents\AgentArtifactApprovals;
use App\Services\Agents\AgentRunResumer;
use App\Services\Brain\BrainProposalService;
use App\Services\Enterprise\RequisitionService;
use App\Services\Enterprise\WorkforceService;
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

        // Procurement: approved means approved for buying. The hook does not
        // create a purchase order and does not touch invoices.
        'requisition' => [RequisitionService::class, 'onApprovalResolved'],

        // Leave: approved means the absence is granted. The hook does not
        // change pay, contracts, or employee status.
        'leave_request' => [WorkforceService::class, 'onApprovalResolved'],
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

    /*
    | How the action a single-stage decision owes is run (ApprovalActions).
    | `transactional` is a claim about the hook: it writes only to this
    | database, jobs it dispatches go after commit, and it can run inside one
    | transaction with its action record. Each entry below was checked against
    | its hook. Anything not listed is `external` — claimed before it runs,
    | and never re-run blind if it dies part-way — which is the safe default
    | for a hook nobody has checked (outreach_reply sends a message; the legacy
    | sales_script / expense_report / bill / payment hooks are unchecked).
    */
    'action_delivery' => [
        'agent_artifact' => 'transactional',   // ArtifactApplier: templates, assets, events
        'agent_tool_call' => 'transactional',  // AgentRunResumer: run state; the resume job is dispatched after commit
        'business_plan' => 'transactional',    // BusinessLaunchService: launch status and events
        'requisition' => 'transactional',      // RequisitionService: status and structural events only
        'leave_request' => 'transactional',    // WorkforceService: leave status and structural events only
    ],

    // A transactional action still failing after this many attempts is failed.
    'action_max_attempts' => (int) env('APPROVAL_ACTION_MAX_ATTEMPTS', 5),

    // An external action claimed this long ago without reporting back is uncertain.
    'action_lease_seconds' => (int) env('APPROVAL_ACTION_LEASE_SECONDS', 900),

];
