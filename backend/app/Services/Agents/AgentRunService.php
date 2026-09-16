<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Events\AgentRunUpdated;
use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\AwarenessItem;
use App\Models\TenantSkill;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use App\Services\Agents\Exceptions\BreakerPausedException;
use App\Services\Agents\Exceptions\IdentityAgentMissingException;
use App\Services\Agents\Exceptions\PackNotEntitledException;
use App\Services\Agents\Exceptions\RuntimeDisabledException;
use App\Services\Agents\Exceptions\SkillDisabledException;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use App\Services\MetaPlanner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lifecycle of a run from the outside (plan D3): start (resolve card →
 * tenant_skills, installing lazily → identity agent → workspace → breaker →
 * replay-idempotent create → MetaPlanner::dispatchRun), cancel, retry and
 * answer (gap questions → BrainStore::upsertSection → re-queue).
 *
 * Hard Rule #2: nothing here enqueues a job itself; MetaPlanner::dispatchRun
 * is the only entry into the runtime.
 */
final class AgentRunService
{
    public function __construct(
        private readonly MetaPlanner $planner,
        private readonly WorkspaceProvisioner $workspaces,
        private readonly EventStore $events,
        private readonly PackEntitlementResolver $entitlements,
        private readonly RunContextFactory $contexts,
    ) {}

    /** Both the config master switch and the per-tenant flag must be on (config/agents.php). */
    public static function runtimeEnabled(string $tenantId): bool
    {
        return (bool) config('agents.runtime_enabled', false) && FeatureFlag::on('agents.runtime', $tenantId);
    }

    /**
     * Create the run and hand it to MetaPlanner. Returns the existing run
     * for a duplicate trigger_ref (never a second run), or a `blocked` run
     * with `questions` when the brain lacks what the card requires.
     */
    public function start(
        string $tenantId,
        string $skillSlug,
        array $inputs = [],
        string $triggerType = AgentRun::TRIGGER_MANUAL,
        ?string $triggerRef = null,
        ?string $requestedBy = null,
    ): AgentRun {
        $run = $this->create($tenantId, $skillSlug, $inputs, $triggerType, $triggerRef, $requestedBy);

        if ($run->wasRecentlyCreated && $run->status === AgentRun::STATUS_QUEUED) {
            $this->planner->dispatchRun($run);
        }

        return $run->refresh();
    }

    /**
     * Everything start() does except dispatching (agents:run --sync runs the
     * job inline through MetaPlanner::dispatchRun($run, inline: true)).
     */
    public function create(
        string $tenantId,
        string $skillSlug,
        array $inputs = [],
        string $triggerType = AgentRun::TRIGGER_MANUAL,
        ?string $triggerRef = null,
        ?string $requestedBy = null,
    ): AgentRun {
        $card = $this->contexts->card($skillSlug);
        $skillSlug = $card->id() ?: $skillSlug;

        if (! self::runtimeEnabled($tenantId)) {
            throw new RuntimeDisabledException($tenantId);
        }
        if (! in_array($triggerType, AgentRun::TRIGGER_TYPES, true)) {
            $triggerType = AgentRun::TRIGGER_MANUAL;
        }

        if ($triggerRef !== null && ($existing = $this->existingFor($tenantId, $skillSlug, $triggerRef)) !== null) {
            return $existing;
        }

        $agentHint = $inputs['agent_id'] ?? null;
        $automation = $inputs['_automation_level'] ?? $inputs['automation_level'] ?? null;
        unset($inputs['agent_id'], $inputs['_automation_level']);

        $tenantSkill = $this->ensureInstalled($tenantId, $card, is_string($agentHint) ? $agentHint : null);
        if (! $tenantSkill->enabled && in_array($triggerType, [AgentRun::TRIGGER_EVENT, AgentRun::TRIGGER_SCHEDULED], true)) {
            throw new SkillDisabledException($skillSlug);
        }

        $agentId = $this->resolveAgent($tenantId, $card, $tenantSkill, is_string($agentHint) ? $agentHint : null);
        $agentSlug = (string) DB::table('agents')->where('id', $agentId)->value('slug');
        $workspaceSlug = $card->runsOn() ?: (IdentityResolver::identityForAgentSlug($agentSlug) ?? $agentSlug);
        $workspace = $this->workspaces->ensure($tenantId, $agentId, $workspaceSlug);

        if ($tenantSkill->agent_id !== $agentId || $tenantSkill->workspace_id !== $workspace->id) {
            $tenantSkill->forceFill(['agent_id' => $agentId, 'workspace_id' => $workspace->id])->save();
        }

        $paused = Collaborators::breakerReason($tenantId, $agentId, $skillSlug, null);
        if ($paused !== null) {
            throw new BreakerPausedException($paused);
        }

        $state = [
            'requested_by' => $requestedBy,
            'automation_level' => is_string($automation) && in_array($automation, TenantSkill::LADDER, true) ? $automation : null,
            'card_version' => $card->version(),
        ];

        try {
            $run = AgentRun::create([
                'tenant_id' => $tenantId,
                'workspace_id' => $workspace->id,
                'agent_id' => $agentId,
                'skill_slug' => $skillSlug,
                'mode' => $card->mode(),
                'trigger_type' => $triggerType,
                'trigger_ref' => $triggerRef,
                'triggered_by' => is_string($requestedBy) && Str::isUuid($requestedBy) ? $requestedBy : null,
                'status' => AgentRun::STATUS_QUEUED,
                'inputs' => $inputs,
                'outputs' => [],
                'state' => $state,
                'brain_snapshot' => [],
                'questions' => [],
            ]);
        } catch (QueryException $e) {
            // Lost the race on the partial unique (tenant, skill, trigger_ref).
            if ($triggerRef !== null && ($existing = $this->existingFor($tenantId, $skillSlug, $triggerRef)) !== null) {
                return $existing;
            }
            throw $e;
        }

        $this->events->append($tenantId, 'agent_run', (string) $run->id, 'agent.run.queued', [
            'run_id' => $run->id,
            'skill_slug' => $skillSlug,
            'workspace_id' => $workspace->id,
            'agent_id' => $agentId,
            'mode' => $run->mode,
            'trigger_type' => $triggerType,
            'trigger_ref' => $triggerRef,
            'requested_by' => $requestedBy,
        ], ['runtime' => 'php_skill']);

        // Pre-flight gap check so the API answers 422 {missing_brain} at once
        // instead of a queued run that blocks a second later. The runner
        // re-checks on claim.
        $gaps = Collaborators::gaps($tenantId, $card->brainRequires());
        if ($gaps !== []) {
            $this->block($run, $card, $workspace, $gaps);
        }

        return $run;
    }

    public function cancel(AgentRun $run, ?string $by = null, string $reason = ''): AgentRun
    {
        if ($run->isTerminal()) {
            return $run;
        }

        $run->forceFill([
            'status' => AgentRun::STATUS_CANCELLED,
            'error' => $reason !== '' ? mb_substr($reason, 0, 2000) : null,
            'finished_at' => now(),
            'lease_expires_at' => null,
            'claimed_by' => null,
        ])->save();

        $this->events->append((string) $run->tenant_id, 'agent_run', (string) $run->id, 'agent.run.cancelled', [
            'run_id' => $run->id, 'skill_slug' => $run->skill_slug, 'by' => $by, 'reason' => $reason,
        ], ['runtime' => 'php_skill']);

        $workspace = $run->workspace_id ? AgentWorkspace::find($run->workspace_id) : null;
        if ($workspace !== null && (string) $workspace->last_run_id === (string) $run->id) {
            $this->workspaces->markStatus($workspace, AgentWorkspace::STATUS_IDLE);
        }
        AgentRunUpdated::safeBroadcast($run);

        return $run;
    }

    public function retry(AgentRun $run, ?string $by = null): AgentRun
    {
        if (! in_array($run->status, [AgentRun::STATUS_FAILED, AgentRun::STATUS_CANCELLED, AgentRun::STATUS_BLOCKED], true)) {
            throw new AgentRuntimeException("Run cannot be retried from status [{$run->status}].", 'run_not_retryable', 409, ['status' => $run->status]);
        }
        if (! self::runtimeEnabled((string) $run->tenant_id)) {
            throw new RuntimeDisabledException((string) $run->tenant_id);
        }

        $state = (array) ($run->state ?? []);
        unset($state['loop_result'], $state['post_action_cursor'], $state['outputs_so_far'], $state['pending_tool_call'], $state['messages'], $state['iteration']);
        $state['retries'] = (int) ($state['retries'] ?? 0) + 1;
        $state['retried_by'] = $by;

        $run->forceFill([
            'status' => AgentRun::STATUS_QUEUED,
            'error' => null,
            'questions' => [],
            'claimed_by' => null,
            'lease_expires_at' => null,
            'finished_at' => null,
            'state' => $state,
        ])->save();

        $this->events->append((string) $run->tenant_id, 'agent_run', (string) $run->id, 'agent.run.retried', [
            'run_id' => $run->id, 'skill_slug' => $run->skill_slug, 'by' => $by, 'attempt' => $state['retries'],
        ], ['runtime' => 'php_skill']);

        $card = $this->contexts->card((string) $run->skill_slug);
        $gaps = Collaborators::gaps((string) $run->tenant_id, $card->brainRequires());
        if ($gaps !== []) {
            $this->block($run, $card, $run->workspace_id ? AgentWorkspace::find($run->workspace_id) : null, $gaps);

            return $run;
        }

        $this->planner->dispatchRun($run);

        return $run->refresh();
    }

    /**
     * Answers to a blocked run's questions: each {path, section, text} lands
     * in the brain through BrainStore::upsertSection, then the run is
     * re-queued (or stays blocked with the remaining questions).
     *
     * @param  list<array{path: string, section?: string, text: string}>  $answers
     */
    public function answer(AgentRun $run, array $answers, ?string $userId = null): AgentRun
    {
        if (! in_array($run->status, [AgentRun::STATUS_BLOCKED, AgentRun::STATUS_WAITING_INPUT], true)) {
            throw new AgentRuntimeException("Run is not waiting for answers (status [{$run->status}]).", 'run_not_answerable', 409, ['status' => $run->status]);
        }

        $store = Collaborators::brainStore();
        if ($store === null) {
            throw new AgentRuntimeException('BrainStore is not available in this build.', 'brain_store_missing', 503);
        }

        $tenantId = (string) $run->tenant_id;
        $written = [];
        foreach ($answers as $answer) {
            $path = trim((string) ($answer['path'] ?? ''));
            $section = trim((string) ($answer['section'] ?? ''));
            $text = trim((string) ($answer['text'] ?? ''));
            if ($path === '' || $text === '' || str_contains($path, '..')) {
                continue;
            }
            $store->upsertSection($tenantId, $path, $section !== '' ? $section : 'Notes', $text, 'human', $userId);
            $written[] = ['path' => $path, 'section' => $section];
        }

        $this->events->append($tenantId, 'agent_run', (string) $run->id, 'agent.run.answered', [
            'run_id' => $run->id, 'skill_slug' => $run->skill_slug, 'answers' => $written, 'by' => $userId,
        ], ['runtime' => 'php_skill']);

        $card = $this->contexts->card((string) $run->skill_slug);
        $gaps = Collaborators::gaps($tenantId, $card->brainRequires());
        if ($gaps !== []) {
            $run->forceFill(['questions' => $gaps])->save();
            AgentRunUpdated::safeBroadcast($run);

            return $run;
        }

        $run->forceFill([
            'status' => AgentRun::STATUS_QUEUED,
            'questions' => [],
            'error' => null,
            'claimed_by' => null,
            'lease_expires_at' => null,
        ])->save();
        $this->resolveAwareness($tenantId, $card);
        $this->planner->dispatchRun($run);

        return $run->refresh();
    }

    // ------------------------------------------------------------------ //
    //  helpers
    // ------------------------------------------------------------------ //

    private function existingFor(string $tenantId, string $skillSlug, string $triggerRef): ?AgentRun
    {
        return AgentRun::forTenant($tenantId)->where('skill_slug', $skillSlug)->where('trigger_ref', $triggerRef)->first();
    }

    /**
     * tenant_skills row for the card, installed on first run when the pack
     * is entitled ("provided when needed"). SkillInstaller (Stream B1) does
     * the real install; a minimal row is written when it is not available.
     */
    private function ensureInstalled(string $tenantId, SkillCardView $card, ?string $agentHint): TenantSkill
    {
        $existing = TenantSkill::forTenant($tenantId)->where('skill_slug', $card->id())->first();
        if ($existing !== null) {
            return $existing;
        }

        $packId = $card->packId();
        if ($packId !== null && ! $this->entitlements->entitled($tenantId, $packId)) {
            throw new PackNotEntitledException($packId, $this->entitlements->checkoutHint($packId));
        }

        $agentSlug = null;
        if ($agentHint !== null) {
            $agentSlug = DB::table('agents')->where('tenant_id', $tenantId)
                ->where(Str::isUuid($agentHint) ? 'id' : 'slug', $agentHint)
                ->value('slug');
            $agentSlug = $agentSlug ? (string) $agentSlug : null;
        }

        $installer = Collaborators::skillInstaller();
        if ($installer !== null) {
            try {
                $installed = $installer->installForTenant($tenantId, $card->id(), $agentSlug, 'catalogue');
                if ($installed instanceof TenantSkill) {
                    return $installed;
                }
            } catch (\Throwable $e) {
                Log::warning('SkillInstaller failed; provisioning a minimal tenant_skills row', ['skill' => $card->id(), 'error' => $e->getMessage()]);
            }
            $row = TenantSkill::forTenant($tenantId)->where('skill_slug', $card->id())->first();
            if ($row !== null) {
                return $row;
            }
        }

        return TenantSkill::create([
            'tenant_id' => $tenantId,
            'skill_slug' => $card->id(),
            'enabled' => true,
            'autonomy_level' => $card->defaultAutonomy(),
            'tool_overrides' => [],
            'budget_daily_usd' => $card->dailyBudgetUsd(),
            'state' => ['installed_from' => 'first_run', 'card_version' => $card->version()],
            'enabled_at' => now(),
        ]);
    }

    private function resolveAgent(string $tenantId, SkillCardView $card, TenantSkill $tenantSkill, ?string $agentHint): string
    {
        if ($agentHint !== null) {
            $id = DB::table('agents')->where('tenant_id', $tenantId)
                ->where(Str::isUuid($agentHint) ? 'id' : 'slug', $agentHint)
                ->value('id');
            if ($id) {
                return (string) $id;
            }
        }

        if ($tenantSkill->agent_id) {
            return (string) $tenantSkill->agent_id;
        }

        $identity = $card->runsOn() ?: (IdentityResolver::identityForSkill($card->id()) ?? '');
        $id = $identity !== '' ? IdentityResolver::resolveAgentId($tenantId, $identity) : null;
        if ($id === null) {
            throw new IdentityAgentMissingException($identity, $identity !== '' ? IdentityResolver::agentSlugFor($identity) : null);
        }

        return $id;
    }

    /** @param list<array{path: string, section: ?string, question: ?string, reason: ?string}> $gaps */
    private function block(AgentRun $run, SkillCardView $card, ?AgentWorkspace $workspace, array $gaps): void
    {
        $run->forceFill(['status' => AgentRun::STATUS_BLOCKED, 'questions' => $gaps])->save();

        $this->events->append((string) $run->tenant_id, 'agent_run', (string) $run->id, 'agent.run.blocked_missing_knowledge', [
            'run_id' => $run->id, 'skill_slug' => $run->skill_slug, 'questions' => $gaps, 'count' => count($gaps), 'at' => 'start',
        ], ['runtime' => 'php_skill']);

        AgentRunner::raiseAwareness((string) $run->tenant_id, $card, $run, $gaps);
        $this->workspaces->markStatus($workspace, AgentWorkspace::STATUS_NEEDS_ATTENTION, (string) $run->id);
        AgentRunUpdated::safeBroadcast($run);
    }

    private function resolveAwareness(string $tenantId, SkillCardView $card): void
    {
        try {
            if (! Schema::hasTable('awareness_items')) {
                return;
            }
            AwarenessItem::forTenant($tenantId)->open()->where('raised_by', $card->id())
                ->where('title', sprintf('%s needs one answer before it can run', $card->displayName()))
                ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::debug('awareness item resolve skipped', ['error' => $e->getMessage()]);
        }
    }
}
