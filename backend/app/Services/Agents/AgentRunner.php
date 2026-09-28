<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Events\AgentRunUpdated;
use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\AgentWorkspace;
use App\Models\AwarenessItem;
use App\Models\TenantSkill;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use App\Services\Agents\Exceptions\RunParkedException;
use App\Services\CostGovernor;
use App\Services\EventStore;
use App\Services\Tools\ToolGateway;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Executes one run inside its workspace (plan D3):
 *
 *   claim (conditional UPDATE queued → claimed + lease)
 *   → BrainSyncService::syncIfStale
 *   → BrainGapAnalyzer: gaps ⇒ blocked + questions + awareness item
 *   → BrainSnapshot::capture (pinned on the run)
 *   → RunBudget::assert
 *   → SkillLoop (single_shot | agentic)
 *   → post_actions through ToolGateway (drafts.save_sequence, drafts.submit_for_review…)
 *   → outputs incl. next_steps[] (card one_step_further) → succeeded
 *
 * Failures finalise as `failed` with `error` and never create an approval.
 * A parked tool call leaves the run on waiting_approval; resume() continues.
 */
final class AgentRunner
{
    public function __construct(
        private readonly EventStore $events,
        private readonly RunBudget $budget,
        private readonly SkillLoop $loop,
        private readonly ToolGateway $tools,
        private readonly CostGovernor $costs,
        private readonly WorkspaceProvisioner $workspaces,
        private readonly RunContextFactory $contexts,
    ) {}

    public function run(AgentRun $run): AgentRun
    {
        if (! $this->claim($run, AgentRun::STATUS_QUEUED)) {
            return $run->refresh();
        }

        $run->refresh();
        $trace = new RunTrace($run, $this->events);
        $ctx = null;

        try {
            $ctx = $this->contexts->forRun($run, $trace);
            $this->workspaces->markStatus($ctx->workspace, AgentWorkspace::STATUS_WORKING, (string) $run->id);
            $trace->runEvent('claimed', ['claimed_by' => $run->claimed_by, 'lease_expires_at' => $run->lease_expires_at?->toIso8601String()]);

            $paused = Collaborators::breakerReason($ctx->tenantId, $ctx->agentId(), $ctx->skillSlug(), null);
            if ($paused !== null) {
                throw new AgentRuntimeException('circuit_breaker_paused: '.$paused, 'circuit_breaker_paused', 423);
            }

            Collaborators::syncBrainIfStale($ctx->tenantId);

            $gaps = Collaborators::gaps($ctx->tenantId, $ctx->card->brainRequires());
            if ($gaps !== []) {
                $this->block($ctx, $gaps);

                return $run->refresh();
            }

            $this->pinSnapshot($ctx);
            $this->budget->assert($ctx, (float) (($run->state ?? [])['estimated_cost'] ?? 0.02));

            $run->forceFill(['status' => AgentRun::STATUS_RUNNING])->save();
            $trace->runEvent('started', ['mode' => $ctx->card->mode()]);

            $result = $ctx->card->mode() === AgentRun::MODE_AGENTIC
                ? $this->loop->runAgentic($ctx)
                : $this->loop->runSingleShot($ctx);

            $this->completeFrom($ctx, $result, 0);
        } catch (RunParkedException $e) {
            $this->parked($ctx, $run, $e);
        } catch (\Throwable $e) {
            $this->fail($ctx, $run, $trace, $e);
        }

        return $run->refresh();
    }

    /** Continue a run whose pending tool call (or post-action) was decided by a human. */
    public function resume(AgentRun $run): AgentRun
    {
        if (! $this->claim($run, AgentRun::STATUS_WAITING_APPROVAL)) {
            return $run->refresh();
        }

        $run->refresh();
        $trace = new RunTrace($run, $this->events);
        $ctx = null;

        try {
            $ctx = $this->contexts->forRun($run, $trace, rebuildSnapshot: true);

            // An approval authorises an action, not the security environment
            // that existed when it was requested. The breaker can trip between
            // the human reading the card and pressing approve, so the scope is
            // re-checked here. ToolGateway re-checks the tool itself, but only
            // after the model has been called and paid for, and a paused skill
            // should not be drafting at all. Failing closed costs the human a
            // second click after they resume; the alternative costs a send.
            $paused = Collaborators::breakerReason($ctx->tenantId, $ctx->agentId(), $ctx->skillSlug(), null);
            if ($paused !== null) {
                throw new AgentRuntimeException('circuit_breaker_paused: '.$paused, 'circuit_breaker_paused', 423);
            }

            $this->budget->assert($ctx, (float) (($run->state ?? [])['estimated_cost'] ?? 0.02));
            $this->workspaces->markStatus($ctx->workspace, AgentWorkspace::STATUS_WORKING, (string) $run->id);
            $run->forceFill(['status' => AgentRun::STATUS_RUNNING])->save();
            $trace->runEvent('resumed', ['pending_tool_call' => ($run->state ?? [])['pending_tool_call'] ?? null]);

            $state = (array) ($run->state ?? []);
            if ($ctx->card->mode() === AgentRun::MODE_AGENTIC) {
                $result = $this->loop->resumeAgentic($ctx);
                $this->completeFrom($ctx, $result, 0);
            } else {
                // single_shot parked inside post_actions: replay from the cursor
                $result = (array) ($state['loop_result'] ?? []);
                $cursor = (int) ($state['post_action_cursor'] ?? 0);
                $this->completeFrom($ctx, $result, $cursor, resumingPending: true);
            }
        } catch (RunParkedException $e) {
            $this->parked($ctx, $run, $e);
        } catch (\Throwable $e) {
            $this->fail($ctx, $run, $trace, $e);
        }

        return $run->refresh();
    }

    // ------------------------------------------------------------------ //
    //  phases
    // ------------------------------------------------------------------ //

    /** Conditional UPDATE so two workers never run the same row. */
    private function claim(AgentRun $run, string $fromStatus): bool
    {
        $lease = max(30, (int) config('agents.lease_seconds', 300));
        $claimedBy = gethostname().':'.getmypid().':'.Str::lower(Str::random(6));

        $updated = AgentRun::query()
            ->whereKey($run->id)
            ->where('status', $fromStatus)
            ->update([
                'status' => AgentRun::STATUS_CLAIMED,
                'claimed_by' => $claimedBy,
                'lease_expires_at' => now()->addSeconds($lease),
                'started_at' => $run->started_at ?? now(),
                'updated_at' => now(),
            ]);

        return $updated === 1;
    }

    private function pinSnapshot(RunContext $ctx): void
    {
        $snapshot = Collaborators::captureSnapshot($ctx->tenantId, $ctx->card->readPaths());
        $ctx->snapshot = $snapshot;

        $map = [];
        $hash = null;
        if ($snapshot !== null) {
            try {
                $map = method_exists($snapshot, 'toArray') ? (array) $snapshot->toArray() : [];
                $hash = method_exists($snapshot, 'hash') ? (string) $snapshot->hash() : null;
            } catch (\Throwable $e) {
                Log::warning('brain snapshot could not be serialised', ['run_id' => $ctx->run->id, 'error' => $e->getMessage()]);
            }
        }

        $state = (array) ($ctx->run->state ?? []);
        $state['brain_snapshot_hash'] = $hash;
        $state['brain_paths'] = $ctx->card->readPaths();
        $ctx->run->forceFill(['brain_snapshot' => $map, 'state' => $state])->save();
        $ctx->trace->step(AgentRunStep::KIND_NOTE, 'brain_snapshot', ['paths' => $ctx->card->readPaths()], ['hash' => $hash, 'pinned' => count($map)]);
    }

    /**
     * Post actions + outputs + finalisation, resumable from a post-action
     * cursor when a single-shot run parked inside its post actions.
     *
     * @param  array<string, mixed>  $result  SkillLoop result
     */
    private function completeFrom(RunContext $ctx, array $result, int $cursor, bool $resumingPending = false): void
    {
        $run = $ctx->run;
        $state = (array) ($run->state ?? []);
        $outputs = (array) ($state['outputs_so_far'] ?? []);
        $data = (array) ($result['data'] ?? []);
        $actions = $ctx->card->postActions();

        for ($i = $cursor; $i < count($actions); $i++) {
            $action = $actions[$i];
            [$tool, $params] = $this->postActionCall($ctx, $action, $data, $outputs);
            if ($tool === null) {
                $ctx->trace->step(AgentRunStep::KIND_NOTE, 'post_action', ['action' => $action], ['skipped' => 'unsupported_post_action']);

                continue;
            }

            $options = [];
            $pending = (array) ($state['pending_tool_call'] ?? []);
            if ($resumingPending && $i === $cursor && $pending !== [] && ($pending['tool'] ?? null) === $tool) {
                if (($pending['decision'] ?? null) !== 'approved') {
                    throw new AgentRuntimeException("post_action_rejected: {$tool}", 'post_action_rejected', 422);
                }
                $options = ['approved' => true];
                unset($state['pending_tool_call']);
                $run->forceFill(['state' => $state])->save();
            }

            $call = $this->tools->call($ctx, $tool, $params, $options);

            if (! empty($call['awaiting_approval'])) {
                $state = (array) $run->refresh()->state;
                $state['loop_result'] = Arr::except($result, ['raw']);
                $state['post_action_cursor'] = $i;
                $state['outputs_so_far'] = $outputs;
                $run->forceFill(['state' => $state])->save();
                throw new RunParkedException((string) ($call['approval_id'] ?? ''), $tool);
            }
            if (empty($call['success'])) {
                throw new AgentRuntimeException("post_action_failed: {$tool}: ".(string) ($call['error'] ?? 'unknown'), 'post_action_failed', 422);
            }

            $outputs = $this->mergeOutputs($outputs, $tool, (array) ($call['data'] ?? []));
        }

        $outputs = $this->finalOutputs($ctx, $data, $result, $outputs);
        $this->succeed($ctx, $outputs);
    }

    /**
     * Map a card post_action to a tool call built from the validated output.
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function postActionCall(RunContext $ctx, string $action, array $data, array $outputs): array
    {
        $inputs = $ctx->inputs();

        switch ($action) {
            case 'drafts.save_sequence':
            case 'save_drafts':
            case 'save_sequence':
                $steps = $data['steps'] ?? $data['sequence'] ?? $data['emails'] ?? [];
                if (! is_array($steps) || $steps === []) {
                    return [null, []];
                }

                return ['drafts.save_sequence', [
                    'campaign' => (string) ($inputs['campaign'] ?? $data['campaign'] ?? $ctx->card->displayName()),
                    'channel' => (string) ($inputs['channel'] ?? $data['channel'] ?? 'email'),
                    'segment' => $inputs['segment'] ?? $data['segment'] ?? null,
                    'steps' => self::normaliseSequenceSteps($steps),
                ]];

            case 'drafts.save':
            case 'save_draft':
                return ['drafts.save', [
                    'kind' => (string) ($data['kind'] ?? $this->defaultArtifactKind($ctx)),
                    'title' => (string) ($data['title'] ?? $data['subject'] ?? $ctx->card->displayName()),
                    'content' => (string) ($data['content'] ?? $data['body'] ?? $data['text'] ?? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
                    'meta' => (array) ($data['meta'] ?? Arr::except($data, ['content', 'body', 'text'])),
                ]];

            case 'drafts.submit_for_review':
            case 'submit_for_review':
                $artifactId = $outputs['sequence_id'] ?? $outputs['artifact_id'] ?? null;
                if ($artifactId === null) {
                    return [null, []];
                }

                return ['drafts.submit_for_review', ['artifact_id' => (string) $artifactId]];

            default:
                return [null, []];
        }
    }

    /**
     * Validated output steps → drafts.save_sequence params. Cards describe a
     * step as {step, beat, subjects[2], body, send_day, cta,
     * personalisation_slot} (cold-email-drafting); the tool wants
     * {n, subject, body, delay_days, variants[{key, subject, body}]} — one
     * variant per subject line, same body (A/B on the subject only).
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    public static function normaliseSequenceSteps(array $steps): array
    {
        $out = [];
        foreach (array_values($steps) as $i => $step) {
            if (! is_array($step)) {
                continue;
            }
            $subjects = array_values(array_filter(array_map('strval', (array) ($step['subjects'] ?? $step['subject_alternatives'] ?? [])), 'strlen'));
            $subject = trim((string) ($step['subject'] ?? ($subjects[0] ?? '')));
            $body = trim((string) ($step['body'] ?? $step['content'] ?? ''));

            $variants = [];
            foreach ((array) ($step['variants'] ?? []) as $variant) {
                if (is_array($variant)) {
                    $variants[] = $variant;
                }
            }
            if ($variants === [] && count($subjects) > 1) {
                foreach ($subjects as $k => $line) {
                    $variants[] = ['key' => chr(ord('a') + $k), 'subject' => $line, 'body' => $body];
                }
            }

            $out[] = array_filter([
                'n' => (int) ($step['n'] ?? $step['step'] ?? $i + 1),
                'subject' => $subject,
                'body' => $body,
                'delay_days' => isset($step['delay_days']) ? (int) $step['delay_days'] : (isset($step['send_day']) ? (int) $step['send_day'] : null),
                'subject_alternatives' => $subjects,
                'variants' => $variants,
                'beat' => isset($step['beat']) ? (string) $step['beat'] : null,
                'cta' => isset($step['cta']) ? (string) $step['cta'] : null,
                'personalisation_slot' => isset($step['personalisation_slot']) ? (string) $step['personalisation_slot'] : null,
            ], static fn ($v) => $v !== null && $v !== []);
        }

        return $out;
    }

    private function defaultArtifactKind(RunContext $ctx): string
    {
        foreach ($ctx->card->outputs() as $output) {
            $kind = (string) ($output['kind'] ?? '');
            if (in_array($kind, AgentArtifact::KINDS, true)) {
                return $kind;
            }
        }

        return AgentArtifact::KIND_NOTE;
    }

    /** @return array<string, mixed> */
    private function mergeOutputs(array $outputs, string $tool, array $data): array
    {
        return match ($tool) {
            'drafts.save_sequence' => $outputs + [
                'sequence_id' => $data['sequence_id'] ?? null,
                'artifact_ids' => (array) ($data['artifact_ids'] ?? []),
                'paths' => (array) ($data['paths'] ?? []),
                'campaign_key' => $data['campaign_key'] ?? null,
                'step_count' => $data['step_count'] ?? null,
            ],
            'drafts.save' => $outputs + ['artifact_id' => $data['artifact_id'] ?? null, 'paths' => [$data['path'] ?? null]],
            'drafts.submit_for_review' => $outputs + ['approval_id' => $data['approval_id'] ?? null],
            default => $outputs + [$tool => $data],
        };
    }

    /** @return array<string, mixed> */
    private function finalOutputs(RunContext $ctx, array $data, array $result, array $outputs): array
    {
        $outputs['summary'] = (string) ($data['summary'] ?? $data['rationale'] ?? '');
        $outputs['model'] = $result['model'] ?? null;
        $outputs['prompt_version'] = $result['prompt_version'] ?? null;
        $outputs['repaired'] = (bool) ($result['repaired'] ?? false);
        $outputs['data'] = Arr::except($data, ['steps', 'sequence', 'emails', 'next_steps']);
        $outputs['artifact_count'] = AgentArtifact::where('run_id', $ctx->run->id)->count();
        $outputs['next_steps'] = $this->nextSteps($ctx, $data, $outputs);

        return $outputs;
    }

    /**
     * outputs.next_steps[] (plan D8 "one step further"): the card's steps
     * with `inputs_from` dotted paths resolved against this run, plus any
     * model-suggested steps when the card allows them.
     *
     * @return list<array<string, mixed>>
     */
    private function nextSteps(RunContext $ctx, array $data, array $outputs): array
    {
        $osf = $ctx->card->oneStepFurther();
        $scope = ['inputs' => $ctx->inputs(), 'outputs' => $outputs, 'data' => $data];
        $steps = [];

        foreach ((array) ($osf['steps'] ?? []) as $step) {
            if (! is_array($step) || empty($step['skill'])) {
                continue;
            }
            $resolved = [];
            foreach ((array) ($step['inputs_from'] ?? []) as $target => $path) {
                $resolved[(string) $target] = is_string($path) ? Arr::get($scope, $path) : $path;
            }
            $steps[] = [
                'id' => (string) ($step['id'] ?? Str::slug((string) $step['skill'])),
                'origin' => 'card',
                'label' => (string) ($step['label'] ?? Str::headline((string) $step['skill'])),
                'does' => (string) ($step['does'] ?? ''),
                'skill' => (string) $step['skill'],
                'inputs' => $resolved,
                'when' => (string) ($step['when'] ?? 'on_success'),
                'risk' => (string) ($step['risk'] ?? 'draft'),
                'cost_hint_usd' => isset($step['cost_hint_usd']) ? (float) $step['cost_hint_usd'] : null,
                'state' => 'proposed',
                'run_id' => null,
            ];
        }

        if (! empty($osf['allow_dynamic'])) {
            foreach ((array) ($data['next_steps'] ?? []) as $step) {
                if (! is_array($step) || empty($step['skill'])) {
                    continue;
                }
                $steps[] = [
                    'id' => (string) ($step['id'] ?? 'model-'.Str::slug((string) $step['skill'])),
                    'origin' => 'model',
                    'label' => (string) ($step['label'] ?? Str::headline((string) $step['skill'])),
                    'does' => (string) ($step['does'] ?? ''),
                    'skill' => (string) $step['skill'],
                    'inputs' => (array) ($step['inputs'] ?? []),
                    'when' => (string) ($step['when'] ?? 'on_success'),
                    'risk' => (string) ($step['risk'] ?? 'draft'),
                    'cost_hint_usd' => isset($step['cost_hint_usd']) ? (float) $step['cost_hint_usd'] : null,
                    'state' => 'proposed',
                    'run_id' => null,
                ];
            }
        }

        return $steps;
    }

    // ------------------------------------------------------------------ //
    //  terminal transitions
    // ------------------------------------------------------------------ //

    private function succeed(RunContext $ctx, array $outputs): void
    {
        $run = $ctx->run;
        $state = (array) $run->refresh()->state;
        unset($state['loop_result'], $state['post_action_cursor'], $state['outputs_so_far'], $state['pending_tool_call']);

        $tokens = (int) $run->tokens + $ctx->spentTokens;
        $cost = (float) $run->cost_usd + $ctx->spentUsd;

        $run->forceFill([
            'status' => AgentRun::STATUS_SUCCEEDED,
            'outputs' => $outputs,
            'state' => $state,
            'tokens' => $tokens,
            'cost_usd' => round($cost, 6),
            'error' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ])->save();

        $this->recordUsage($ctx, $cost, $tokens, $outputs);

        $workspaceStatus = ! empty($outputs['approval_id']) ? AgentWorkspace::STATUS_NEEDS_REVIEW : AgentWorkspace::STATUS_IDLE;
        $this->workspaces->markStatus($ctx->workspace, $workspaceStatus, (string) $run->id);
        $this->rememberOnSkill($ctx, ['last_run_id' => $run->id, 'last_run_status' => AgentRun::STATUS_SUCCEEDED, 'last_run_at' => now()->toIso8601String()]);

        $ctx->trace->runEvent(AgentRun::STATUS_SUCCEEDED, [
            'tokens' => $tokens,
            'cost_usd' => round($cost, 6),
            'approval_id' => $outputs['approval_id'] ?? null,
            'artifact_count' => $outputs['artifact_count'] ?? 0,
            'next_steps' => count((array) ($outputs['next_steps'] ?? [])),
        ]);
        AgentRunUpdated::safeBroadcast($run);
    }

    /** @param list<array{path: string, section: ?string, question: ?string, reason: ?string}> $gaps */
    private function block(RunContext $ctx, array $gaps): void
    {
        $run = $ctx->run;
        $run->forceFill([
            'status' => AgentRun::STATUS_BLOCKED,
            'questions' => $gaps,
            'lease_expires_at' => null,
            'claimed_by' => null,
        ])->save();

        $ctx->trace->step(AgentRunStep::KIND_NOTE, 'brain_gaps', ['requires' => $ctx->card->brainRequires()], ['gaps' => $gaps]);
        $ctx->trace->runEvent('blocked_missing_knowledge', ['questions' => $gaps, 'count' => count($gaps)]);

        self::raiseAwareness($ctx->tenantId, $ctx->card, $run, $gaps);
        $this->workspaces->markStatus($ctx->workspace, AgentWorkspace::STATUS_NEEDS_ATTENTION, (string) $run->id);
        AgentRunUpdated::safeBroadcast($run);
    }

    /** An awareness_items row so Needs-You Today and the awareness list show the one question. */
    public static function raiseAwareness(string $tenantId, SkillCardView $card, AgentRun $run, array $gaps): void
    {
        try {
            if (! Schema::hasTable('awareness_items')) {
                return;
            }
            $first = $gaps[0] ?? [];
            $title = sprintf('%s needs one answer before it can run', $card->displayName());
            $exists = AwarenessItem::forTenant($tenantId)->open()->where('title', $title)->where('raised_by', $card->id())->exists();
            if ($exists) {
                return;
            }
            AwarenessItem::create([
                'tenant_id' => $tenantId,
                'source' => 'agent',
                'title' => mb_substr($title, 0, 255),
                'detail' => mb_substr(implode("\n", array_filter(array_map(
                    static fn (array $g) => trim(($g['question'] ?? '') !== '' ? (string) $g['question'] : ('Fill '.$g['path'].($g['section'] ? '#'.$g['section'] : ''))),
                    $gaps,
                ))), 0, 4000),
                'severity' => 'info',
                'status' => 'open',
                'raised_by' => mb_substr($card->id(), 0, 64),
            ]);
        } catch (\Throwable $e) {
            Log::debug('awareness item for blocked run skipped', ['run_id' => $run->id, 'error' => $e->getMessage()]);
        }
    }

    private function parked(?RunContext $ctx, AgentRun $run, RunParkedException $e): void
    {
        $run->refresh();
        if ($run->status !== AgentRun::STATUS_WAITING_APPROVAL) {
            $run->forceFill(['status' => AgentRun::STATUS_WAITING_APPROVAL])->save();
        }
        $run->forceFill([
            'tokens' => (int) $run->tokens + ($ctx?->spentTokens ?? 0),
            'cost_usd' => round((float) $run->cost_usd + ($ctx?->spentUsd ?? 0.0), 6),
            'lease_expires_at' => null,
            'claimed_by' => null,
        ])->save();

        if ($ctx !== null) {
            $ctx->trace->runEvent('waiting_approval', ['approval_id' => $e->approvalId, 'tool' => $e->extra['tool'] ?? null]);
            $this->workspaces->markStatus($ctx->workspace, AgentWorkspace::STATUS_NEEDS_REVIEW, (string) $run->id);
        }
        AgentRunUpdated::safeBroadcast($run);
    }

    private function fail(?RunContext $ctx, AgentRun $run, RunTrace $trace, \Throwable $e): void
    {
        $code = $e instanceof AgentRuntimeException ? $e->errorCode : 'exception';
        $message = mb_substr($e->getMessage(), 0, 2000);
        Log::warning('agent run failed', ['run_id' => $run->id, 'skill' => $run->skill_slug, 'code' => $code, 'error' => $message]);

        $run->refresh();
        $run->forceFill([
            'status' => AgentRun::STATUS_FAILED,
            'error' => $message,
            'tokens' => (int) $run->tokens + ($ctx?->spentTokens ?? 0),
            'cost_usd' => round((float) $run->cost_usd + ($ctx?->spentUsd ?? 0.0), 6),
            'lease_expires_at' => null,
            'finished_at' => now(),
        ])->save();

        try {
            $trace->step(AgentRunStep::KIND_ERROR, $code, [], ['message' => $message, 'exception' => get_class($e)], AgentRunStep::STATUS_FAILED);
            $trace->runEvent(AgentRun::STATUS_FAILED, ['error' => $message, 'code' => $code]);
        } catch (\Throwable $inner) {
            Log::error('agent run failure could not be traced', ['run_id' => $run->id, 'error' => $inner->getMessage()]);
        }

        if ($ctx !== null) {
            if ($ctx->spentUsd > 0) {
                $this->recordUsage($ctx, $ctx->spentUsd, $ctx->spentTokens, ['status' => 'failed']);
            }
            $this->workspaces->markStatus($ctx->workspace, AgentWorkspace::STATUS_NEEDS_ATTENTION, (string) $run->id);
            $this->rememberOnSkill($ctx, ['last_run_id' => $run->id, 'last_run_status' => AgentRun::STATUS_FAILED, 'last_run_at' => now()->toIso8601String(), 'last_error' => $message]);
        }
        AgentRunUpdated::safeBroadcast($run);
    }

    private function recordUsage(RunContext $ctx, float $cost, int $tokens, array $outputs): void
    {
        if ($cost <= 0 && $tokens <= 0) {
            return;
        }
        try {
            $this->costs->recordUsage($ctx->tenantId, 'agent_run', round($cost, 6), [
                'intent' => 'run_skill:'.$ctx->skillSlug(),
                'run_id' => $ctx->run->id,
                'skill_slug' => $ctx->skillSlug(),
                'agent_id' => $ctx->agentId(),
                'workspace_id' => $ctx->run->workspace_id,
                'tokens' => $tokens,
                'model' => $outputs['model'] ?? null,
                'status' => $outputs['status'] ?? AgentRun::STATUS_SUCCEEDED,
            ]);
        } catch (\Throwable $e) {
            Log::debug('agent run usage record skipped', ['run_id' => $ctx->run->id, 'error' => $e->getMessage()]);
        }
    }

    /** Skill-private state: the last run summary (tenant_skills.state). */
    private function rememberOnSkill(RunContext $ctx, array $patch): void
    {
        if ($ctx->tenantSkill === null) {
            return;
        }
        try {
            $state = (array) ($ctx->tenantSkill->state ?? []);
            TenantSkill::whereKey($ctx->tenantSkill->id)->update(['state' => json_encode($patch + $state), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::debug('tenant skill state update skipped', ['error' => $e->getMessage()]);
        }
    }
}
