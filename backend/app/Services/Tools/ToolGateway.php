<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\AgentWorkspace;
use App\Models\TenantIntegration;
use App\Models\TenantSkill;
use App\Services\Agents\Collaborators;
use App\Services\Agents\Exceptions\BudgetExceededException;
use App\Services\Agents\RunBudget;
use App\Services\Agents\RunContext;
use App\Services\Agents\WorkspaceProvisioner;
use App\Services\ApprovalEngine;
use App\Services\FeatureFlag;
use Illuminate\Support\Facades\Log;

/**
 * The one door every tool call goes through (plan D4, the VoiceSafetyGuard
 * shape generalised):
 *
 *   flag → allowlist → circuit breaker → autonomy ladder + ApprovalEngine
 *   policy → connector connected → budget → execute → trace + events.
 *
 * Returns `{success, data?, error?, awaiting_approval?, approval_id?}` and
 * never throws for a denial; only the tool's own execution errors surface
 * as `success:false`.
 */
final class ToolGateway
{
    /** Risks that need a human at each rung of the ladder. */
    private const LADDER = [
        TenantSkill::AUTONOMY_HUMAN_LED => [ToolContract::RISK_WRITE, ToolContract::RISK_SEND, ToolContract::RISK_IRREVERSIBLE],
        TenantSkill::AUTONOMY_ASSISTED => [ToolContract::RISK_SEND, ToolContract::RISK_IRREVERSIBLE],
        TenantSkill::AUTONOMY_SHADOW => [ToolContract::RISK_SEND, ToolContract::RISK_IRREVERSIBLE],
        TenantSkill::AUTONOMY_AUTONOMOUS => [ToolContract::RISK_IRREVERSIBLE],
    ];

    public function __construct(
        private readonly ToolCatalogue $catalogue,
        private readonly ApprovalEngine $approvals,
        private readonly RunBudget $budget,
        private readonly WorkspaceProvisioner $workspaces,
    ) {}

    public function catalogue(): ToolCatalogue
    {
        return $this->catalogue;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{approved?: bool}  $options  `approved` skips the ladder (a human already said yes)
     * @return array{success: bool, data?: array<string, mixed>, error?: string, denied?: bool, awaiting_approval?: bool, approval_id?: string}
     */
    public function call(RunContext $ctx, string $tool, array $params = [], array $options = []): array
    {
        $tenantId = $ctx->tenantId;
        $contract = $this->catalogue->get($tool);
        if ($contract === null) {
            return $this->deny($ctx, $tool, $params, 'unknown_tool');
        }

        $risk = in_array($contract->risk(), ToolContract::RISKS, true) ? $contract->risk() : ToolContract::RISK_WRITE;

        // 1. Feature flags. With agents.tools off only the core brain/drafts
        //    tools work, so PR 1 runs with everything else off.
        if (! $this->catalogue->isCore($tool) && ! FeatureFlag::on('agents.tools', $tenantId)) {
            return $this->deny($ctx, $tool, $params, 'tools_flag_off');
        }
        if ($risk === ToolContract::RISK_SEND && ! FeatureFlag::on('agents.tools.send', $tenantId)) {
            return $this->deny($ctx, $tool, $params, 'send_flag_off');
        }
        if ($risk === ToolContract::RISK_IRREVERSIBLE && ! FeatureFlag::on('agents.tools.irreversible', $tenantId)) {
            return $this->deny($ctx, $tool, $params, 'irreversible_flag_off');
        }

        // 2. Allowlist: card tools ∪ tenant overrides.allow − overrides.deny.
        if (! $ctx->allows($tool)) {
            return $this->deny($ctx, $tool, $params, 'not_in_allowlist');
        }

        // 3. Circuit breaker (D8 #6) — scope tenant / agent / skill / risk.
        $paused = Collaborators::breakerReason($tenantId, $ctx->agentId(), $ctx->skillSlug(), $risk);
        if ($paused !== null) {
            return $this->deny($ctx, $tool, $params, 'breaker_paused:'.$paused);
        }

        // 4. Autonomy ladder + tenant approval policy.
        if (empty($options['approved']) && $this->needsApproval($ctx, $contract, $params)) {
            return $this->park($ctx, $contract, $params);
        }

        // 5. Connector connected.
        $provider = $contract->requiresConnector();
        if ($provider !== null && ! $this->connectorConnected($tenantId, $provider)) {
            return $this->deny($ctx, $tool, $params, 'connector_not_connected:'.$provider);
        }

        // 6. Budget.
        $estimate = $this->catalogue->costOf($tool);
        try {
            $this->budget->assert($ctx, $estimate);
        } catch (BudgetExceededException $e) {
            return $this->deny($ctx, $tool, $params, $e->errorCode, $e->extra);
        }

        // 7. Execute + trace.
        $ctx->trace->step(AgentRunStep::KIND_TOOL_CALL, $tool, $params, ['risk' => $risk], AgentRunStep::STATUS_OK);
        $started = microtime(true);

        try {
            $result = $contract->execute($ctx, $params);
        } catch (\Throwable $e) {
            Log::warning('agent tool execution failed', ['tool' => $tool, 'run_id' => $ctx->run->id, 'error' => $e->getMessage()]);
            $ctx->trace->step(
                AgentRunStep::KIND_TOOL_RESULT, $tool, [], ['error' => $e->getMessage()],
                AgentRunStep::STATUS_FAILED, 0, 0.0, (int) ((microtime(true) - $started) * 1000),
            );

            return ['success' => false, 'error' => 'tool_error: '.$e->getMessage()];
        }

        $cost = $result->cost > 0 ? $result->cost : $estimate;
        $this->budget->record($ctx, $cost);
        $ctx->trace->step(
            AgentRunStep::KIND_TOOL_RESULT,
            $tool,
            [],
            $result->success ? $result->data : ['error' => (string) $result->error],
            $result->success ? AgentRunStep::STATUS_OK : AgentRunStep::STATUS_FAILED,
            0,
            $cost,
            (int) ((microtime(true) - $started) * 1000),
        );

        return $result->toArray();
    }

    /** Does this call need a human at the run's autonomy level (or by tenant policy)? */
    public function needsApproval(RunContext $ctx, ToolContract $tool, array $params = []): bool
    {
        $risk = $tool->risk();
        $level = $ctx->autonomy();
        $gated = self::LADDER[$level] ?? self::LADDER[TenantSkill::AUTONOMY_HUMAN_LED];

        if (in_array($risk, $gated, true)) {
            return true;
        }

        try {
            $policy = $this->approvals->matchPolicy($ctx->tenantId, 'agent_tool_call', $tool->name(), [
                'tool' => $tool->name(),
                'risk' => $risk,
                'skill_slug' => $ctx->skillSlug(),
                'autonomy_level' => $level,
                'agent_id' => $ctx->agentId(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('approval policy lookup failed; requiring approval', ['tool' => $tool->name(), 'error' => $e->getMessage()]);

            return true;
        }

        return $policy !== null;
    }

    /**
     * Park the run on an `agent_tool_call` approval. The approval's
     * resource_id is the run id; the pending call lives in run.state so
     * AgentRunResumer / ResumeAgentRunJob can continue exactly there.
     *
     * @return array{success: false, awaiting_approval: true, approval_id: string}
     */
    private function park(RunContext $ctx, ToolContract $tool, array $params): array
    {
        $run = $ctx->run;
        $risk = $tool->risk();

        $approval = $this->approvals->createChainedApproval(
            $ctx->tenantId,
            $ctx->agentId() ?? $ctx->tenantId,
            'agent_tool_call',
            'agent_tool_call',
            (string) $run->id,
            sprintf('%s wants to run %s (%s)', $ctx->card->displayName(), $tool->name(), $risk),
            [
                'action' => $tool->name(),
                'attributes' => ['tool' => $tool->name(), 'risk' => $risk, 'skill_slug' => $ctx->skillSlug(), 'autonomy_level' => $ctx->autonomy()],
                'run_id' => $run->id,
                'workspace_id' => $run->workspace_id,
                'skill_slug' => $ctx->skillSlug(),
                'tool' => $tool->name(),
                'params' => $params,
                'risk' => $risk,
            ],
        );
        $approvalId = (string) ($approval['id'] ?? '');

        $state = (array) ($run->state ?? []);
        $state['pending_tool_call'] = [
            'tool' => $tool->name(),
            'params' => $params,
            'risk' => $risk,
            'approval_id' => $approvalId,
            'requested_at' => now()->toIso8601String(),
            'decision' => null,
        ];
        $run->forceFill(['status' => AgentRun::STATUS_WAITING_APPROVAL, 'state' => $state])->save();

        $ctx->trace->step(AgentRunStep::KIND_APPROVAL, $tool->name(), $params, ['approval_id' => $approvalId, 'risk' => $risk], AgentRunStep::STATUS_PENDING);
        $this->workspaces->markStatus($ctx->workspace, AgentWorkspace::STATUS_NEEDS_REVIEW, (string) $run->id);

        return ['success' => false, 'awaiting_approval' => true, 'approval_id' => $approvalId];
    }

    /** @return array{success: false, error: string, denied: true} */
    private function deny(RunContext $ctx, string $tool, array $params, string $reason, array $detail = []): array
    {
        $ctx->trace->step(AgentRunStep::KIND_TOOL_CALL, $tool, $params, ['reason' => $reason] + $detail, AgentRunStep::STATUS_DENIED);

        return ['success' => false, 'error' => $reason, 'denied' => true];
    }

    private function connectorConnected(string $tenantId, string $provider): bool
    {
        try {
            return TenantIntegration::forTenant($tenantId)->where('provider', $provider)->where('is_active', true)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
