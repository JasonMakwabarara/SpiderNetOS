<?php

declare(strict_types=1);

namespace App\Services\Launch;

use App\Models\BusinessLaunch;
use App\Services\ApprovalEngine;
use App\Services\Brain\BrainGapAnalyzer;
use App\Services\EventStore;
use App\Services\Interviews\InterviewRunner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Atlas, I want to start a business" (plan D7 §5) — the state machine:
 *
 *   purchased → interviewing → researching → modelling → drafted
 *             → awaiting_approval → approved → live
 *
 * Sequencing is deterministic. The runner walks stages.yaml in order and asks
 * whichever interview question defines the first missing variable of the
 * current stage (required first, then the ones the stage `uses`). When a
 * stage has nothing missing, StageCommitter writes its brain files in one
 * version per file and the launch moves on; generated artefacts (the finance
 * model, the plan) come from LaunchArtefacts and the Python service.
 *
 * Statuses only move forwards — a rejected plan returns to `drafted`, never
 * behind answers already given.
 *
 * Nothing this service produces is legal or financial advice, and every
 * payload says so.
 */
class BusinessLaunchService
{
    public const EVENT_STARTED = 'launch.started';

    public const EVENT_ANSWERED = 'launch.answer.recorded';

    public const EVENT_SUBMITTED = 'launch.plan.submitted';

    public const EVENT_APPROVED = 'launch.plan.approved';

    public const EVENT_LIVE = 'launch.live';

    public function __construct(
        private readonly StageCommitter $stages,
        private readonly LaunchArtefacts $artefacts,
        private readonly JurisdictionPack $jurisdictions,
        private readonly EventStore $events,
        private readonly ApprovalEngine $approvals,
        private readonly BrainGapAnalyzer $gaps,
    ) {}

    // ── lifecycle ──────────────────────────────────────────────────────

    public function find(string $tenantId): ?BusinessLaunch
    {
        if (! Str::isUuid($tenantId)) {
            return null;    // tenant_id is a uuid column; Postgres aborts on a bad one
        }

        return BusinessLaunch::forTenant($tenantId)
            ->where('pack_id', $this->packId())
            ->orderByDesc('created_at')
            ->first();
    }

    public function getOrCreate(string $tenantId): BusinessLaunch
    {
        return $this->find($tenantId) ?? BusinessLaunch::create([
            'tenant_id' => $tenantId,
            'pack_id' => $this->packId(),
            'status' => BusinessLaunch::STATUS_PURCHASED,
            'interview_answers' => [],
            'stage_artifacts' => [],
        ]);
    }

    /**
     * purchased → interviewing, optionally fixing the jurisdiction up front.
     * Idempotent: every answer and every Atlas turn calls this, so the event
     * is emitted only when something actually moved.
     */
    public function start(string $tenantId, ?string $jurisdiction = null): BusinessLaunch
    {
        $launch = $this->getOrCreate($tenantId);

        $code = $jurisdiction !== null ? StageCommitter::codeFrom($jurisdiction) : null;
        $attributes = [];
        if ($code !== null && $code !== $launch->jurisdiction && $this->jurisdictions->has($code)) {
            $attributes['jurisdiction'] = $code;
        }
        if (! $launch->atLeast(BusinessLaunch::STATUS_INTERVIEWING)) {
            $attributes['status'] = BusinessLaunch::STATUS_INTERVIEWING;
            $attributes['current_stage'] = $this->stages->stageIds()[0] ?? null;
        }
        if ($attributes === []) {
            return $launch;
        }

        $launch->update($attributes);
        $launch->refresh();

        $this->events->append(
            $launch->tenant_id,
            'business_launch',
            $launch->id,
            self::EVENT_STARTED,
            ['launch_id' => $launch->id, 'pack_id' => $launch->pack_id, 'jurisdiction' => $launch->jurisdiction],
        );

        return $launch;
    }

    public function setJurisdiction(BusinessLaunch $launch, string $jurisdiction): BusinessLaunch
    {
        $code = StageCommitter::codeFrom($jurisdiction) ?? strtolower(trim($jurisdiction));
        if (! $this->jurisdictions->has($code)) {
            return $launch;
        }
        $launch->update(['jurisdiction' => $code]);

        return $launch->refresh();
    }

    // ── interview ──────────────────────────────────────────────────────

    /**
     * The question to ask next: the first unanswered variable of the first
     * incomplete stage, required before optional.
     *
     * @return array<string, mixed>|null null when the interview is finished
     */
    public function nextQuestion(BusinessLaunch $launch): ?array
    {
        $runner = $this->runner($launch);

        foreach ($this->stages->stages() as $stage) {
            foreach ($this->stageVariables($stage) as $variable) {
                $questionId = $runner->questionIdForVariable($variable);
                if ($questionId === null || $runner->isAnswered($questionId)) {
                    continue;
                }
                $question = $runner->findQuestion(null, $questionId);
                if ($question === null) {
                    continue;
                }

                return [
                    'id' => $questionId,
                    'variable' => $variable,
                    'prompt' => (string) ($question['prompt'] ?? $questionId),
                    'type' => (string) ($question['type'] ?? 'text'),
                    'choices' => array_values(array_map('strval', (array) ($question['choices'] ?? []))),
                    'required' => in_array($variable, $this->stages->requiredVariables($stage), true),
                    'section' => $runner->sectionIdFor($questionId),
                    'stage' => (string) ($stage['id'] ?? ''),
                    'stage_title' => (string) ($stage['title'] ?? ''),
                    'now_filling' => (string) data_get($question, 'brain_target.path', ''),
                    'brain_section' => (string) data_get($question, 'brain_target.section', ''),
                ];
            }
        }

        return null;
    }

    /**
     * Record one answer, commit the stage when nothing required is missing,
     * and advance the state machine. `$questionId` defaults to whatever
     * nextQuestion() would have asked, which is how Atlas routes a plain
     * chat message into the interview.
     *
     * @return array<string, mixed> the launch state after the answer
     */
    public function answer(BusinessLaunch $launch, string $answer, ?string $questionId = null, bool $skip = false): array
    {
        $asked = $this->nextQuestion($launch);
        $questionId ??= $asked['id'] ?? null;

        if ($questionId === null) {
            return $this->state($launch) + ['recorded' => false, 'reason' => 'interview_complete'];
        }

        $runner = $this->runner($launch);
        if ($runner->findQuestion(null, $questionId) === null) {
            return $this->state($launch) + ['recorded' => false, 'reason' => 'unknown_question'];
        }

        $text = trim($answer);
        $skip = $skip || $text === '';
        $runner->recordAnswer($questionId, $text, $skip);
        $launch->refresh();

        // The jurisdiction answer fixes the currency the finance model uses.
        if ($questionId === 'jurisdiction' && ! $skip) {
            $code = StageCommitter::codeFrom($text);
            if ($code !== null && $this->jurisdictions->has($code)) {
                $launch->update(['jurisdiction' => $code]);
                $launch->refresh();
            }
        }

        $this->events->append(
            $launch->tenant_id,
            'business_launch',
            $launch->id,
            self::EVENT_ANSWERED,
            ['launch_id' => $launch->id, 'question_id' => $questionId, 'skipped' => $skip, 'stage' => $launch->current_stage],
        );

        $committed = $this->advance($launch);

        return $this->state($launch) + ['recorded' => true, 'question_id' => $questionId, 'skipped' => $skip, 'committed' => $committed];
    }

    /**
     * Commit one stage explicitly (also the `launch_commit_stage` tool path).
     *
     * @return array<string, mixed>
     */
    public function commitStage(BusinessLaunch $launch, string $stageId): array
    {
        $stage = $this->stages->stage($stageId);
        if ($stage === null) {
            return ['committed' => false, 'reason' => 'unknown_stage', 'stage' => $stageId];
        }

        $result = $this->stages->commit($launch, $stageId);
        $launch->refresh();

        if ($result['committed']) {
            $this->promote($launch, (string) ($stage['status'] ?? ''));
            $this->advance($launch);
        }

        return $result + ['state' => $this->state($launch)];
    }

    /**
     * Move the launch to the first stage that still needs something and
     * commit every earlier stage whose required answers are all in.
     *
     * @return list<string> stage ids committed by this call
     */
    public function advance(BusinessLaunch $launch): array
    {
        $committed = [];
        $current = null;

        foreach ($this->stages->stages() as $stage) {
            $stageId = (string) ($stage['id'] ?? '');
            if ($stageId === '') {
                continue;
            }

            $missing = $this->stages->missingRequired($launch, $stage);
            if ($missing === []) {
                $result = $this->stages->commit($launch, $stageId);
                $launch->refresh();
                if ($result['committed'] && $result['files'] !== []) {
                    $committed[] = $stageId;
                }
                $this->promote($launch, (string) ($stage['status'] ?? ''));
            }

            if ($current === null && ! $this->isStageComplete($launch, $stage)) {
                $current = $stageId;
            }
        }

        $current ??= $this->stages->stageIds()[count($this->stages->stageIds()) - 1] ?? null;
        if ($launch->current_stage !== $current) {
            $launch->update(['current_stage' => $current]);
            $launch->refresh();
        }

        return $committed;
    }

    // ── generation ─────────────────────────────────────────────────────

    /**
     * @param  list<string>  $targets  research | finance | plan
     * @return array<string, mixed>
     */
    public function generate(BusinessLaunch $launch, array $targets): array
    {
        $targets = array_values(array_intersect(
            array_map('strval', $targets),
            LaunchArtefacts::TARGETS,
        ));
        if ($targets === []) {
            $targets = ['finance'];
        }

        $results = $this->artefacts->generate($launch, $targets);
        $launch->refresh();

        if (in_array('research', $targets, true) && ($results['research']['ok'] ?? false)) {
            $this->promote($launch, BusinessLaunch::STATUS_RESEARCHING);
        }
        if (in_array('finance', $targets, true) && ($results['finance']['ok'] ?? false)) {
            $this->promote($launch, BusinessLaunch::STATUS_MODELLING);
        }
        if (in_array('plan', $targets, true) && ($results['plan']['ok'] ?? false)) {
            $this->promote($launch, BusinessLaunch::STATUS_DRAFTED);
        }

        $this->advance($launch);

        return ['results' => $results, 'state' => $this->state($launch)];
    }

    // ── approval ───────────────────────────────────────────────────────

    /**
     * drafted → awaiting_approval, through ApprovalEngine (resource type
     * `business_plan`, registered in config/approvals.php).
     *
     * @return array<string, mixed>
     */
    public function submit(BusinessLaunch $launch, string $requestedByUserId): array
    {
        $deliverables = (array) data_get($launch->stage_artifacts ?? [], 'deliverables', []);
        if (! isset($deliverables['plan/business-plan.md'])) {
            return ['submitted' => false, 'reason' => 'no_plan', 'state' => $this->state($launch)];
        }
        if ($launch->status === BusinessLaunch::STATUS_AWAITING_APPROVAL && $launch->approval_id) {
            return ['submitted' => true, 'approval_id' => $launch->approval_id, 'state' => $this->state($launch)];
        }

        $approval = $this->approvals->createApproval(
            tenantId: $launch->tenant_id,
            requesterId: $requestedByUserId,
            type: 'manual',
            resourceType: 'business_plan',
            resourceId: $launch->id,
            reason: 'Approve your business plan so Atlas can put it live in your brain. '.$this->stages->disclaimer(),
            context: [
                'launch_id' => $launch->id,
                'pack_id' => $launch->pack_id,
                'jurisdiction' => $launch->jurisdiction,
                'brain_path' => 'plan/business-plan.md',
                'disclaimer' => $this->stages->disclaimer(),
            ],
        );

        $launch->update([
            'approval_id' => $approval['id'] ?? null,
            'status' => BusinessLaunch::STATUS_AWAITING_APPROVAL,
        ]);
        $launch->refresh();

        $this->events->append(
            $launch->tenant_id,
            'business_launch',
            $launch->id,
            self::EVENT_SUBMITTED,
            ['launch_id' => $launch->id, 'approval_id' => $approval['id'] ?? null],
        );

        return ['submitted' => true, 'approval_id' => $approval['id'] ?? null, 'approval' => $approval, 'state' => $this->state($launch)];
    }

    /**
     * ApprovalEngine resource hook (config/approvals.php → business_plan).
     * Granted takes the plan live; a rejection drops back to `drafted` so the
     * founder can revise and resubmit.
     */
    public function onApprovalResolved(string $tenantId, string $resourceId, bool $granted, ?string $response = null): void
    {
        if (! Str::isUuid($resourceId)) {
            return;     // business_launches.id is a uuid column
        }

        $launch = BusinessLaunch::forTenant($tenantId)->find($resourceId);
        if ($launch === null) {
            Log::warning('BusinessLaunchService: approval for an unknown launch', ['launch_id' => $resourceId]);

            return;
        }

        if (! $granted) {
            $launch->update(['status' => BusinessLaunch::STATUS_DRAFTED]);

            return;
        }

        $launch->update([
            'status' => BusinessLaunch::STATUS_LIVE,
            'went_live_at' => now(),
        ]);
        $launch->refresh();

        foreach ([self::EVENT_APPROVED, self::EVENT_LIVE] as $event) {
            $this->events->append(
                $launch->tenant_id,
                'business_launch',
                $launch->id,
                $event,
                ['launch_id' => $launch->id, 'approval_id' => $launch->approval_id, 'response' => $response],
            );
        }
    }

    // ── state ──────────────────────────────────────────────────────────

    /**
     * Everything the cockpit panel and Atlas's `metadata.launch` need.
     *
     * @return array<string, mixed>
     */
    public function state(BusinessLaunch $launch): array
    {
        $next = $this->nextQuestion($launch);
        $progress = $this->progress($launch);
        $stageId = (string) ($launch->current_stage ?? ($this->stages->stageIds()[0] ?? ''));
        $stage = $this->stages->stage($stageId);

        return [
            'id' => $launch->id,
            'pack_id' => $launch->pack_id,
            'status' => $launch->status,
            'stage' => $stageId,
            'stage_title' => (string) ($stage['title'] ?? ''),
            'jurisdiction' => $launch->jurisdiction,
            'jurisdictions' => $this->jurisdictions->codes(),
            'progress_pct' => $progress['pct'],
            'progress' => $progress,
            'next_question' => $next,
            'now_filling' => $next['now_filling'] ?? null,
            'stages' => $this->stageSummaries($launch),
            'deliverables' => $this->artefacts->deliverables($launch),
            'approval_id' => $launch->approval_id,
            'went_live_at' => $launch->went_live_at?->toIso8601String(),
            'disclaimer' => $this->stages->disclaimer(),
        ];
    }

    /**
     * Brain readiness for the launch surface (Atlas `metadata.brain`).
     *
     * @return array{pct: int, files: list<array<string, mixed>>}
     */
    public function readiness(string $tenantId): array
    {
        try {
            return $this->gaps->readiness($tenantId);
        } catch (\Throwable $e) {
            Log::warning('BusinessLaunchService: readiness failed', ['error' => $e->getMessage()]);

            return ['pct' => 0, 'files' => []];
        }
    }

    /**
     * @return array{pct: int, answered: int, total: int, stages_committed: int, stages_total: int}
     */
    public function progress(BusinessLaunch $launch): array
    {
        $runner = $this->runner($launch);
        $deliverables = (array) data_get($launch->stage_artifacts ?? [], 'deliverables', []);

        $variables = [];
        $generated = [];
        foreach ($this->stages->stages() as $stage) {
            foreach ($this->stageVariables($stage) as $variable) {
                $variables[$variable] = true;
            }
            foreach ((array) ($stage['artefacts'] ?? []) as $artefact) {
                if (! empty($artefact['generated_by'])) {
                    $generated[(string) ($artefact['path'] ?? '')] = true;
                }
            }
        }
        unset($generated['']);

        // A question the founder deliberately skipped is dealt with, not
        // outstanding — the bar must be able to reach 100.
        $asked = 0;
        foreach (array_keys($variables) as $variable) {
            $questionId = $runner->questionIdForVariable($variable);
            if ($questionId !== null && $runner->isAnswered($questionId)) {
                $asked++;
            }
        }

        $done = $asked + count(array_intersect_key($deliverables, $generated));
        $total = count($variables) + count($generated);

        $committed = (array) data_get($launch->stage_artifacts ?? [], 'stages', []);
        $pct = $total > 0 ? (int) round(100 * $done / $total) : 0;
        if ($launch->status === BusinessLaunch::STATUS_LIVE) {
            $pct = 100;
        }

        return [
            'pct' => min(100, max(0, $pct)),
            'answered' => $done,
            'total' => $total,
            'stages_committed' => count($committed),
            'stages_total' => count($this->stages->stages()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function checklist(BusinessLaunch $launch, string $code): ?array
    {
        return $this->jurisdictions->checklist($code, $this->artefacts->complianceProfile($launch));
    }

    // ── internals ──────────────────────────────────────────────────────

    /**
     * Required variables first (in declaration order), then the ones the
     * stage merely uses — exactly the order stages.yaml describes.
     *
     * @param  array<string, mixed>  $stage
     * @return list<string>
     */
    private function stageVariables(array $stage): array
    {
        return array_values(array_unique(array_merge(
            $this->stages->requiredVariables($stage),
            $this->stages->optionalVariables($stage),
        )));
    }

    /** @param array<string, mixed> $stage */
    private function isStageComplete(BusinessLaunch $launch, array $stage): bool
    {
        $runner = $this->runner($launch);
        foreach ($this->stageVariables($stage) as $variable) {
            $questionId = $runner->questionIdForVariable($variable);
            if ($questionId !== null && ! $runner->isAnswered($questionId)) {
                return false;
            }
        }

        return $this->stages->generatedArtefactsPresent($launch, $stage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stageSummaries(BusinessLaunch $launch): array
    {
        $committed = (array) data_get($launch->stage_artifacts ?? [], 'stages', []);
        $out = [];

        foreach ($this->stages->stages() as $stage) {
            $stageId = (string) ($stage['id'] ?? '');
            $out[] = [
                'id' => $stageId,
                'title' => (string) ($stage['title'] ?? $stageId),
                'status' => (string) ($stage['status'] ?? ''),
                'agent' => (string) ($stage['agent'] ?? ''),
                'writes' => array_values(array_map('strval', (array) ($stage['writes'] ?? []))),
                'committed' => isset($committed[$stageId]),
                'committed_at' => $committed[$stageId]['committed_at'] ?? null,
                'missing_required' => $this->stages->missingRequired($launch, $stage),
                'complete' => $this->isStageComplete($launch, $stage),
                'current' => $stageId === $launch->current_stage,
            ];
        }

        return $out;
    }

    /** Move the status forwards only. */
    private function promote(BusinessLaunch $launch, string $status): void
    {
        if ($status === '' || BusinessLaunch::rank($status) < 0) {
            return;
        }
        if (BusinessLaunch::rank($status) <= BusinessLaunch::rank($launch->status)) {
            return;
        }
        // Approval and go-live are never reached by walking stages.
        if (BusinessLaunch::rank($status) > BusinessLaunch::rank(BusinessLaunch::STATUS_DRAFTED)) {
            return;
        }
        $launch->update(['status' => $status]);
        $launch->refresh();
    }

    private function runner(BusinessLaunch $launch): InterviewRunner
    {
        return $this->stages->runner($launch);
    }

    private function packId(): string
    {
        return (string) config('launch.pack_id', 'business-launch');
    }
}
