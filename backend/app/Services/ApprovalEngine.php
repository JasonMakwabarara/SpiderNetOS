<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Services\EventStore;
use App\Services\DagExecutionService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ApprovalEngine — SpiderNet OS v3.2
 *
 * Human-in-the-loop approval gates. Any DAG node (or external action) can
 * be paused until a designated approver grants or rejects the request.
 * Approval policies are configurable per tenant / resource-type / action.
 *
 * Two modes coexist:
 *  - legacy single-stage approvals (current_step IS NULL) — unchanged;
 *  - policy-driven multi-stage chains (current_step set) created by
 *    createChainedApproval() and resolved step-by-step via resolveStep().
 */
class ApprovalEngine
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DagExecutionService $dagExecutionService,
        private readonly ?NotificationService $notifications = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Create / Resolve
    // ------------------------------------------------------------------ //

    /**
     * Create a new approval request.
     *
     * @param  string       $tenantId      Tenant scope.
     * @param  string       $requesterId   User or agent that initiated the request.
     * @param  string       $type          Approval type (e.g. "manual", "budget", "security").
     * @param  string       $resourceType  The kind of resource under review (e.g. "flow_execution", "deployment").
     * @param  string       $resourceId    Identifier of the specific resource instance.
     * @param  string       $reason        Human-readable justification for the request.
     * @param  array        $context       Arbitrary metadata attached to the approval.
     * @return array        The created approval record.
     */
    public function createApproval(
        string $tenantId,
        string $requesterId,
        string $type,
        string $resourceType,
        string $resourceId,
        string $reason,
        array  $context = [],
    ): array {
        $approvalId = (string) Str::uuid();

        $record = [
            'id'            => $approvalId,
            'tenant_id'     => $tenantId,
            'requester_id'  => $requesterId,
            'approval_type' => $type,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'reason'        => $reason,
            'context'       => json_encode($context, JSON_THROW_ON_ERROR),
            'status'        => 'pending',
            'requested_at'  => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        DB::table('approvals')->insert($record);

        $this->eventStore->append($tenantId, 'approval.requested', [
            'approval_id'   => $approvalId,
            'requester_id'  => $requesterId,
            'approval_type' => $type,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'reason'        => $reason,
        ]);

        Log::info('Approval requested', compact('approvalId', 'tenantId', 'resourceType', 'resourceId'));

        return $this->formatApproval(
            DB::table('approvals')->where('id', $approvalId)->first()
        );
    }

    /**
     * Resolve an existing approval (grant or reject).
     *
     * @param  string  $approvalId  The approval to resolve.
     * @param  string  $approverId  The user performing the approval action.
     * @param  bool    $approved    True = granted, false = rejected.
     * @param  string  $response    Optional message from the approver.
     * @return array   The updated approval record.
     */
    public function resolveApproval(
        string $approvalId,
        string $approverId,
        bool   $approved,
        string $response = '',
    ): array {
        $approval = DB::table('approvals')->where('id', $approvalId)->first();

        if (!$approval) {
            throw new \InvalidArgumentException("Approval [{$approvalId}] not found.");
        }

        // Chained approvals resolve one step at a time.
        if ($approval->current_step !== null) {
            return $this->resolveStep($approvalId, $approverId, $approved, $response);
        }

        if ($approval->status !== 'pending') {
            throw new \LogicException("Approval [{$approvalId}] has already been resolved (status: {$approval->status}).");
        }

        // Status vocabulary is canonicalized on 'approved' (the value the
        // cockpit UI and ApprovalController already use); domain event names
        // (approval.granted / approval.rejected) are unchanged.
        $newStatus = $approved ? 'approved' : 'rejected';

        DB::table('approvals')
            ->where('id', $approvalId)
            ->update([
                'status'      => $newStatus,
                'approver_id' => $approverId,
                'response'    => $response,
                'responded_at' => now(),
                'updated_at'  => now(),
            ]);

        $eventType = $approved ? 'approval.granted' : 'approval.rejected';

        $this->eventStore->append($approval->tenant_id, $eventType, [
            'approval_id'  => $approvalId,
            'approver_id'  => $approverId,
            'response'     => $response,
        ]);

        Log::info("Approval {$newStatus}", compact('approvalId', 'approverId'));

        // If the approval was granted, attempt to resume any blocked execution.
        if ($approved) {
            $this->resumeBlockedExecution($approvalId);
        }

        return $this->formatApproval(
            DB::table('approvals')->where('id', $approvalId)->first()
        );
    }

    // ------------------------------------------------------------------ //
    //  Multi-stage chains
    // ------------------------------------------------------------------ //

    /**
     * Highest-priority enabled policy whose conditions match the resource
     * attributes and which defines at least one step. Null when no chain
     * is configured for this resource/action.
     */
    public function matchPolicy(
        string $tenantId,
        string $resourceType,
        string $action,
        array $attributes = [],
    ): ?ApprovalPolicy {
        return ApprovalPolicy::forTenant($tenantId)
            ->enabled()
            ->where('resource_type', $resourceType)
            ->where('action', $action)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->with('steps')
            ->get()
            ->first(fn (ApprovalPolicy $policy) => $policy->steps->isNotEmpty() && $policy->matches($attributes));
    }

    /**
     * Create a policy-driven multi-step approval. Falls back to the legacy
     * single-stage createApproval() when no policy matches.
     */
    public function createChainedApproval(
        string $tenantId,
        string $requesterId,
        string $type,
        string $resourceType,
        string $resourceId,
        string $reason,
        array  $context = [],
        ?ApprovalPolicy $policy = null,
    ): array {
        $policy ??= $this->matchPolicy($tenantId, $resourceType, $context['action'] ?? 'submit', $context['attributes'] ?? []);

        if ($policy === null || $policy->steps->isEmpty()) {
            return $this->createApproval($tenantId, $requesterId, $type, $resourceType, $resourceId, $reason, $context);
        }

        return DB::transaction(function () use ($tenantId, $requesterId, $type, $resourceType, $resourceId, $reason, $context, $policy) {
            $approvalId = (string) Str::uuid();

            DB::table('approvals')->insert([
                'id'            => $approvalId,
                'tenant_id'     => $tenantId,
                'requester_id'  => $requesterId,
                'approval_type' => $type,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
                'reason'        => $reason,
                'context'       => json_encode($context, JSON_THROW_ON_ERROR),
                'status'        => 'pending',
                'policy_id'     => $policy->id,
                'current_step'  => 1,
                'requested_at'  => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            foreach ($policy->steps as $template) {
                ApprovalStep::create([
                    'approval_id' => $approvalId,
                    'tenant_id' => $tenantId,
                    'step_order' => $template->step_order,
                    'approver_type' => $template->approver_type,
                    'approver_role' => $template->approver_role,
                    'approver_id' => $template->approver_id,
                    'status' => $template->step_order === 1 ? 'pending' : 'queued',
                    'expires_at' => $template->step_order === 1 && $template->expires_after_hours
                        ? now()->addHours($template->expires_after_hours)
                        : null,
                ]);
            }

            $this->eventStore->append($tenantId, 'approval.requested', [
                'approval_id'   => $approvalId,
                'requester_id'  => $requesterId,
                'approval_type' => $type,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
                'reason'        => $reason,
            ]);

            $this->eventStore->append($tenantId, 'approval.chain_started', [
                'approval_id'   => $approvalId,
                'policy_id'     => $policy->id,
                'policy_name'   => $policy->name,
                'step_count'    => $policy->steps->count(),
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
            ]);

            $this->notifyStepApprovers($tenantId, $approvalId, 1);

            Log::info('Chained approval created', [
                'approvalId' => $approvalId,
                'policyId' => $policy->id,
                'steps' => $policy->steps->count(),
            ]);

            return $this->formatApproval(
                DB::table('approvals')->where('id', $approvalId)->first()
            );
        });
    }

    /**
     * Resolve the CURRENT step of a chained approval. Rejection at any step
     * terminates the whole chain; approval of the final step resolves the
     * approval and fires the resource hook.
     */
    public function resolveStep(
        string $approvalId,
        string $approverId,
        bool   $approved,
        string $response = '',
    ): array {
        return DB::transaction(function () use ($approvalId, $approverId, $approved, $response) {
            $approval = DB::table('approvals')->where('id', $approvalId)->lockForUpdate()->first();

            if (!$approval) {
                throw new \InvalidArgumentException("Approval [{$approvalId}] not found.");
            }
            if ($approval->current_step === null) {
                throw new \LogicException("Approval [{$approvalId}] is not a chained approval.");
            }
            if ($approval->status !== 'pending') {
                throw new \LogicException("Approval [{$approvalId}] has already been resolved (status: {$approval->status}).");
            }

            $step = ApprovalStep::where('approval_id', $approvalId)
                ->where('step_order', $approval->current_step)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$step) {
                throw new \LogicException("Approval [{$approvalId}] has no pending step at position {$approval->current_step}.");
            }

            $approver = User::find($approverId);
            if (!$approver || !$step->actableBy($approver)) {
                throw new \DomainException('User is not authorized to act on this approval step.');
            }

            $step->update([
                'status' => $approved ? 'approved' : 'rejected',
                'acted_by' => $approverId,
                'response' => $response,
                'responded_at' => now(),
            ]);

            $this->eventStore->append($approval->tenant_id, $approved ? 'approval.step_granted' : 'approval.step_rejected', [
                'approval_id' => $approvalId,
                'step_order' => $step->step_order,
                'acted_by' => $approverId,
                'response' => $response,
            ]);

            if (!$approved) {
                ApprovalStep::where('approval_id', $approvalId)
                    ->where('status', 'queued')
                    ->update(['status' => 'skipped', 'updated_at' => now()]);

                $this->finalizeChain($approval, $approverId, false, $response);

                return $this->formatApproval(DB::table('approvals')->where('id', $approvalId)->first());
            }

            $next = ApprovalStep::where('approval_id', $approvalId)
                ->where('status', 'queued')
                ->orderBy('step_order')
                ->first();

            if ($next === null) {
                $this->finalizeChain($approval, $approverId, true, $response);

                return $this->formatApproval(DB::table('approvals')->where('id', $approvalId)->first());
            }

            $template = $approval->policy_id
                ? \App\Models\ApprovalPolicyStep::where('approval_policy_id', $approval->policy_id)
                    ->where('step_order', $next->step_order)
                    ->first()
                : null;

            $next->update([
                'status' => 'pending',
                'expires_at' => $template?->expires_after_hours
                    ? now()->addHours($template->expires_after_hours)
                    : null,
            ]);

            DB::table('approvals')->where('id', $approvalId)->update([
                'current_step' => $next->step_order,
                'updated_at' => now(),
            ]);

            $this->notifyStepApprovers($approval->tenant_id, $approvalId, $next->step_order);

            return $this->formatApproval(DB::table('approvals')->where('id', $approvalId)->first());
        });
    }

    /**
     * Delegate the current pending step to another user.
     */
    public function delegateStep(
        string $approvalId,
        string $fromUserId,
        string $toUserId,
        string $note = '',
    ): array {
        return DB::transaction(function () use ($approvalId, $fromUserId, $toUserId, $note) {
            $approval = DB::table('approvals')->where('id', $approvalId)->lockForUpdate()->first();

            if (!$approval || $approval->current_step === null || $approval->status !== 'pending') {
                throw new \LogicException('Only a pending chained approval can be delegated.');
            }

            $step = ApprovalStep::where('approval_id', $approvalId)
                ->where('step_order', $approval->current_step)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $from = User::find($fromUserId);
            if (!$from || !$step->actableBy($from)) {
                throw new \DomainException('User is not authorized to delegate this approval step.');
            }

            $delegate = User::where('id', $toUserId)
                ->where('tenant_id', $approval->tenant_id)
                ->first();
            if (!$delegate) {
                throw new \DomainException('Delegate must belong to the same tenant.');
            }

            $step->update(['delegated_to' => $toUserId]);

            $this->eventStore->append($approval->tenant_id, 'approval.step_delegated', [
                'approval_id' => $approvalId,
                'step_order' => $step->step_order,
                'from' => $fromUserId,
                'to' => $toUserId,
                'note' => $note,
            ]);

            $this->safeNotifyUser($delegate, 'approval_pending', [
                'title' => 'Approval delegated to you',
                'body' => $note !== '' ? $note : 'A pending approval step was delegated to you.',
                'url' => '/approvals/' . $approvalId,
            ]);

            return $this->formatApproval(DB::table('approvals')->where('id', $approvalId)->first());
        });
    }

    /**
     * Expire (or escalate) pending steps past their deadline. Called from
     * the scheduler. Returns the number of steps acted on.
     */
    public function expireOverdueSteps(): int
    {
        $overdue = ApprovalStep::pending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->limit(200)
            ->get();

        $count = 0;

        foreach ($overdue as $step) {
            DB::transaction(function () use ($step, &$count) {
                $fresh = ApprovalStep::where('id', $step->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if (!$fresh) {
                    return;
                }

                $approval = DB::table('approvals')->where('id', $fresh->approval_id)->lockForUpdate()->first();
                if (!$approval || $approval->status !== 'pending') {
                    return;
                }

                $template = $approval->policy_id
                    ? \App\Models\ApprovalPolicyStep::where('approval_policy_id', $approval->policy_id)
                        ->where('step_order', $fresh->step_order)
                        ->first()
                    : null;

                if ($template?->escalate_to_role) {
                    $fresh->update([
                        'approver_type' => 'role',
                        'approver_role' => $template->escalate_to_role,
                        'approver_id' => null,
                        'expires_at' => now()->addHours($template->expires_after_hours ?? 48),
                    ]);

                    $this->eventStore->append($approval->tenant_id, 'approval.step_escalated', [
                        'approval_id' => $approval->id,
                        'step_order' => $fresh->step_order,
                        'escalated_to_role' => $template->escalate_to_role,
                    ]);

                    $this->safeNotifyRole($approval->tenant_id, [$template->escalate_to_role], 'approval_escalated', [
                        'title' => 'Approval escalated to you',
                        'body' => 'A pending approval step expired and was escalated.',
                        'url' => '/approvals/' . $approval->id,
                    ]);
                } else {
                    $fresh->update(['status' => 'expired', 'responded_at' => now()]);
                    ApprovalStep::where('approval_id', $approval->id)
                        ->where('status', 'queued')
                        ->update(['status' => 'skipped', 'updated_at' => now()]);

                    DB::table('approvals')->where('id', $approval->id)->update([
                        'status' => 'expired',
                        'responded_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $this->eventStore->append($approval->tenant_id, 'approval.step_expired', [
                        'approval_id' => $approval->id,
                        'step_order' => $fresh->step_order,
                    ]);
                    $this->eventStore->append($approval->tenant_id, 'approval.expired', [
                        'approval_id' => $approval->id,
                        'resource_type' => $approval->resource_type,
                        'resource_id' => $approval->resource_id,
                    ]);

                    $this->fireResourceHook($approval->resource_type, $approval->tenant_id, $approval->resource_id, false, 'expired');
                }

                $count++;
            });
        }

        return $count;
    }

    /**
     * Terminal transition shared by grant-on-last-step and reject-anywhere.
     */
    private function finalizeChain(object $approval, string $approverId, bool $granted, string $response): void
    {
        DB::table('approvals')->where('id', $approval->id)->update([
            'status' => $granted ? 'approved' : 'rejected',
            'approver_id' => $approverId,
            'response' => $response,
            'responded_at' => now(),
            'updated_at' => now(),
        ]);

        $this->eventStore->append($approval->tenant_id, $granted ? 'approval.granted' : 'approval.rejected', [
            'approval_id' => $approval->id,
            'approver_id' => $approverId,
            'response' => $response,
        ]);

        if ($granted) {
            $this->resumeBlockedExecution($approval->id);
        }

        $this->fireResourceHook($approval->resource_type, $approval->tenant_id, $approval->resource_id, $granted, $response);
    }

    /**
     * Resource-type activation hooks. Failures propagate so the enclosing
     * transaction rolls back — an approval must not read "approved" while
     * its downstream effect failed to apply.
     */
    private function fireResourceHook(string $resourceType, string $tenantId, string $resourceId, bool $granted, string $response = ''): void
    {
        switch ($resourceType) {
            case 'sales_script':
                $service = app(\App\Services\Sales\FunnelSetupService::class);
                $granted
                    ? $service->activateFromApproval($tenantId, $resourceId)
                    : $service->rejectFromApproval($tenantId, $resourceId, $response);
                break;

            case 'expense_report':
                if (class_exists(\App\Services\Spend\ExpenseService::class)) {
                    app(\App\Services\Spend\ExpenseService::class)->onApprovalResolved($tenantId, $resourceId, $granted);
                }
                break;

            case 'bill':
                if (class_exists(\App\Services\Spend\BillService::class)) {
                    app(\App\Services\Spend\BillService::class)->onApprovalResolved($tenantId, $resourceId, $granted);
                }
                break;

            case 'payment':
                $paymentService = app(\App\Services\Financial\PaymentService::class);
                if (method_exists($paymentService, 'onApprovalResolved')) {
                    $paymentService->onApprovalResolved($tenantId, $resourceId, $granted);
                }
                break;
        }
    }

    /**
     * Notify the approver(s) of a step. Notification failures are logged,
     * never propagated — a push outage must not block approvals.
     */
    private function notifyStepApprovers(string $tenantId, string $approvalId, int $stepOrder): void
    {
        $step = ApprovalStep::where('approval_id', $approvalId)
            ->where('step_order', $stepOrder)
            ->first();
        if (!$step) {
            return;
        }

        $payload = [
            'title' => 'Approval waiting on you',
            'body' => "Step {$stepOrder} of an approval chain is pending your review.",
            'url' => '/approvals/' . $approvalId,
        ];

        if ($step->approver_type === 'user' && $step->approver_id) {
            $user = User::find($step->approver_id);
            if ($user) {
                $this->safeNotifyUser($user, 'approval_pending', $payload);
            }
        } elseif ($step->approver_role) {
            $this->safeNotifyRole($tenantId, [$step->approver_role], 'approval_pending', $payload);
        }
    }

    private function safeNotifyUser(User $user, string $eventType, array $payload): void
    {
        try {
            $this->notifications?->notify($user, $eventType, $payload);
        } catch (\Throwable $e) {
            Log::warning('Approval notification failed', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    private function safeNotifyRole(string $tenantId, array $roles, string $eventType, array $payload): void
    {
        try {
            $this->notifications?->notifyTenantRole($tenantId, $roles, $eventType, $payload);
        } catch (\Throwable $e) {
            Log::warning('Approval role notification failed', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ //
    //  Queries
    // ------------------------------------------------------------------ //

    /**
     * Return all pending approvals for a tenant.
     *
     * @param  string  $tenantId
     * @return array
     */
    public function getPendingApprovals(string $tenantId): array
    {
        return DB::table('approvals')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($row) => $this->formatApproval($row))
            ->toArray();
    }

    /**
     * Check whether an approval gate is configured for the given tenant,
     * resource type, and action combination.
     *
     * Approval policies are stored in the `approval_policies` table:
     *   tenant_id | resource_type | action | enabled
     *
     * @param  string  $tenantId
     * @param  string  $resourceType
     * @param  string  $action
     * @return bool
     */
    public function isApprovalRequired(string $tenantId, string $resourceType, string $action): bool
    {
        $policy = DB::table('approval_policies')
            ->where('tenant_id', $tenantId)
            ->where('resource_type', $resourceType)
            ->where('action', $action)
            ->first();

        return $policy !== null && (bool) $policy->enabled;
    }

    // ------------------------------------------------------------------ //
    //  Execution resumption
    // ------------------------------------------------------------------ //

    /**
     * Resume a DAG execution that was waiting on the given approval.
     *
     * The approval context must contain `execution_id` and `node_id` to
     * identify which execution/node to resume.
     *
     * @param  string  $approvalId
     * @return void
     */
    public function resumeBlockedExecution(string $approvalId): void
    {
        $approval = DB::table('approvals')->where('id', $approvalId)->first();

        if (!$approval) {
            return;
        }

        $context = json_decode($approval->context, true, 512, JSON_THROW_ON_ERROR);

        $executionId = $context['execution_id'] ?? null;
        $nodeId      = $context['node_id'] ?? null;

        if (!$executionId || !$nodeId) {
            Log::debug('Approval has no linked execution; skipping resume.', ['approvalId' => $approvalId]);
            return;
        }

        // Transition the node from waiting_approval back to pending so the
        // DAG scheduler can pick it up.
        DB::table('execution_dag_nodes')
            ->where('execution_id', $executionId)
            ->where('node_id', $nodeId)
            ->where('status', 'waiting_approval')
            ->update([
                'status'     => 'pending',
                'updated_at' => now(),
            ]);

        Log::info('Resuming blocked execution after approval', compact('approvalId', 'executionId', 'nodeId'));

        // Verify immutable pre-approval snapshot before resume.
        $checkpoint = DB::table('approval_checkpoints')
            ->where('execution_id', $executionId)
            ->where('step', $nodeId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();

        if ($checkpoint) {
            $checkpointPayload = json_decode($checkpoint->payload, true);
            $currentExecution = DB::table('flow_executions')->where('id', $executionId)->first();
            $currentContext = $currentExecution ? (json_decode($currentExecution->context, true) ?? []) : [];
            $currentHash = hash('sha256', json_encode([
                'execution_id' => $executionId,
                'node_id' => $nodeId,
                'context' => $currentContext,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $storedHash = $checkpointPayload['immutable_state_hash']
                ?? $checkpointPayload['snapshot_hash']
                ?? null;

            if ($storedHash && $storedHash !== $currentHash) {
                throw new \RuntimeException('State tampering detected on approval resume.');
            }

            DB::table('approval_checkpoints')
                ->where('id', $checkpoint->id)
                ->update([
                    'status' => 'approved',
                    'updated_at' => now(),
                ]);
        }

        // Re-dispatch the node.
        $this->dagExecutionService->executeNode($executionId, $nodeId);
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    /**
     * Normalise a raw DB row into a plain array.
     */
    private function formatApproval(object $row): array
    {
        return [
            'id'            => $row->id,
            'tenant_id'     => $row->tenant_id,
            'requester_id'  => $row->requester_id,
            'approver_id'   => $row->approver_id ?? null,
            'type'          => $row->approval_type,
            'resource_type' => $row->resource_type,
            'resource_id'   => $row->resource_id,
            'reason'        => $row->reason,
            'context'       => json_decode($row->context, true),
            'status'        => $row->status,
            'response'      => $row->response ?? null,
            'resolved_at'   => $row->responded_at ?? null,
            'policy_id'     => $row->policy_id ?? null,
            'current_step'  => $row->current_step ?? null,
            'created_at'    => $row->created_at,
            'updated_at'    => $row->updated_at,
        ];
    }
}
