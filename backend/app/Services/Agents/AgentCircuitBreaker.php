<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Approval;
use App\Models\TenantAgentState;
use App\Models\TenantSkill;
use App\Services\EventStore;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AgentCircuitBreaker (plan D8 #6).
 *
 * One tenant_agent_states row per (tenant, scope, scope_id). "Pause
 * everything" = scope tenant; "Pause Richard" = scope agent; a single card =
 * scope skill; "Stop sends but keep drafting" = scope tool_risk (send).
 * paused stops the scope entirely; demoted keeps drafting but blocks
 * send/irreversible tools and drops the skill one rung on the autonomy
 * ladder. Count-based tripwires (config/agents.php `tripwires`) demote
 * rather than freeze. ToolGateway::call() and AgentRunner::claim() consult
 * isPaused() first (PR 3 call sites).
 */
class AgentCircuitBreaker
{
    public const RISK_ORDER = ['read' => 0, 'write' => 1, 'send' => 2, 'irreversible' => 3];

    /**
     * Tables seen to exist, memoised per process. isPaused() runs on every
     * tool call, often inside a transaction; on Postgres an information_schema
     * probe after any failed statement dies with "current transaction is
     * aborted", so the schema is checked once and only a positive answer is kept.
     *
     * @var array<string, true>
     */
    private static array $tables = [];

    /** Tool risks a demoted scope may no longer use. */
    public const DEMOTED_BLOCKS = ['send', 'irreversible'];

    public const PRESETS = [
        'pause_everything' => ['scope' => TenantAgentState::SCOPE_TENANT, 'label' => 'Pause everything'],
        'stop_sends' => ['scope' => TenantAgentState::SCOPE_TOOL_RISK, 'scope_id' => 'send', 'label' => 'Stop sends but keep drafting'],
        'pause_agent' => ['scope' => TenantAgentState::SCOPE_AGENT, 'label' => 'Pause one agent'],
        'pause_skill' => ['scope' => TenantAgentState::SCOPE_SKILL, 'label' => 'Pause one skill'],
    ];

    /**
     * The reason this call must not proceed, or null when nothing matching is
     * tripped. A paused scope blocks everything; a demoted scope blocks only
     * send/irreversible tool risks.
     */
    public function isPaused(string $tenantId, ?string $agentId = null, ?string $skillSlug = null, ?string $toolRisk = null): ?string
    {
        if (! self::hasTable('tenant_agent_states')) {
            return null;
        }

        $rows = TenantAgentState::forTenant($tenantId)->tripped()->orderBy('created_at')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $agentSlug = $this->agentSlug($tenantId, $agentId);

        foreach ($rows as $row) {
            if ($row->resumeDue()) {
                $this->markResumed($row, 'auto');

                continue;
            }

            if (! $this->matches($row, $agentId, $agentSlug, $skillSlug, $toolRisk)) {
                continue;
            }

            if ($row->state === TenantAgentState::STATE_PAUSED) {
                return $this->describe($row);
            }

            if ($row->state === TenantAgentState::STATE_DEMOTED && $toolRisk !== null && in_array($toolRisk, self::DEMOTED_BLOCKS, true)) {
                return $this->describe($row);
            }
        }

        return null;
    }

    public function pause(
        string $tenantId,
        string $scope,
        ?string $scopeId,
        string $reason,
        string $trippedBy = TenantAgentState::TRIPPED_BY_HUMAN,
        ?\DateTimeInterface $resumeAt = null,
        ?string $userId = null,
    ): TenantAgentState {
        return $this->trip($tenantId, $scope, $scopeId, TenantAgentState::STATE_PAUSED, $reason, $trippedBy, $resumeAt, $userId);
    }

    public function demote(
        string $tenantId,
        string $scope,
        ?string $scopeId,
        string $reason,
        string $trippedBy = TenantAgentState::TRIPPED_BY_TRIPWIRE,
        ?\DateTimeInterface $resumeAt = null,
        ?string $userId = null,
    ): TenantAgentState {
        $state = $this->trip($tenantId, $scope, $scopeId, TenantAgentState::STATE_DEMOTED, $reason, $trippedBy, $resumeAt, $userId);

        if ($scope === TenantAgentState::SCOPE_SKILL && $scopeId !== null) {
            $this->dropAutonomyRung($tenantId, $scopeId, $state);
        }

        return $state;
    }

    public function resume(string $tenantId, string $scope, ?string $scopeId, ?string $userId = null): ?TenantAgentState
    {
        $scope = $this->assertScope($scope);
        $scopeId = $this->normaliseScopeId($scope, $scopeId);

        $row = TenantAgentState::forTenant($tenantId)->where('scope', $scope)->where('scope_id', $scopeId)->first();
        if ($row === null || $row->isRunning()) {
            return $row;
        }

        $this->markResumed($row, $userId ?? 'human');

        return $row->refresh();
    }

    /** @return list<array<string, mixed>> every state row for the tenant (tripped first). */
    public function states(string $tenantId): array
    {
        if (! self::hasTable('tenant_agent_states')) {
            return [];
        }

        return TenantAgentState::forTenant($tenantId)
            ->orderByRaw("CASE state WHEN 'paused' THEN 0 WHEN 'demoted' THEN 1 ELSE 2 END")
            ->orderByDesc('tripped_at')
            ->get()
            ->map(fn (TenantAgentState $s): array => $s->toEnvelope())
            ->values()
            ->all();
    }

    /**
     * Tripwire evaluation for one skill (config/agents.php `tripwires`):
     *   - N rejected approvals in a row → demote
     *   - SkillOutputValidator reject rate over the last window runs → demote
     * Returns the reason when tripped (and applies the demotion), else null.
     */
    public function evaluate(string $tenantId, string $skillSlug): ?string
    {
        if (! self::hasTable('tenant_agent_states')) {
            return null;
        }

        $existing = TenantAgentState::forTenant($tenantId)
            ->where('scope', TenantAgentState::SCOPE_SKILL)->where('scope_id', $skillSlug)->first();
        if ($existing !== null && ! $existing->isRunning() && ! $existing->resumeDue()) {
            return $this->describe($existing);
        }

        $since = $existing?->resumed_at;
        $tripwires = (array) config('agents.tripwires', []);

        $reason = $this->consecutiveRejections($tenantId, $skillSlug, (int) ($tripwires['consecutive_rejected_approvals'] ?? 3), $since)
            ?? $this->validatorRejectRate(
                $tenantId,
                $skillSlug,
                (float) ($tripwires['validator_reject_rate'] ?? 0.30),
                (int) ($tripwires['validator_window_runs'] ?? 20),
                $since,
            );

        if ($reason === null) {
            return null;
        }

        $resumeAfter = $tripwires['resume_after_minutes'] ?? null;
        $resumeAt = is_numeric($resumeAfter) && (int) $resumeAfter > 0 ? now()->addMinutes((int) $resumeAfter) : null;

        $this->demote($tenantId, TenantAgentState::SCOPE_SKILL, $skillSlug, $reason, TenantAgentState::TRIPPED_BY_TRIPWIRE, $resumeAt);

        return $reason;
    }

    // ------------------------------------------------------------------ //
    //  Tripwires
    // ------------------------------------------------------------------ //

    private function consecutiveRejections(string $tenantId, string $skillSlug, int $n, ?Carbon $since): ?string
    {
        if ($n <= 0 || ! self::hasTable('approvals')) {
            return null;
        }

        $artifactApprovalIds = self::hasTable('agent_artifacts')
            ? DB::table('agent_artifacts')->where('tenant_id', $tenantId)->where('skill_slug', $skillSlug)
                ->whereNotNull('approval_id')->pluck('approval_id')->all()
            : [];

        $query = Approval::forTenant($tenantId)
            ->whereIn('status', ['approved', 'rejected'])
            ->where(function ($q) use ($artifactApprovalIds, $skillSlug) {
                $q->where('context->skill_slug', $skillSlug);
                if ($artifactApprovalIds !== []) {
                    $q->orWhereIn('id', $artifactApprovalIds);
                }
            })
            ->orderByDesc('responded_at')->orderByDesc('updated_at')->orderByDesc('created_at')
            ->limit($n);

        if ($since !== null) {
            $query->where(function ($q) use ($since) {
                $q->where('responded_at', '>', $since)->orWhere(function ($q2) use ($since) {
                    $q2->whereNull('responded_at')->where('updated_at', '>', $since);
                });
            });
        }

        $recent = $query->get(['id', 'status']);
        if ($recent->count() < $n) {
            return null;
        }
        if ($recent->every(fn (Approval $a): bool => $a->status === 'rejected')) {
            return "{$n} rejected approvals in a row for {$skillSlug}";
        }

        return null;
    }

    private function validatorRejectRate(string $tenantId, string $skillSlug, float $rate, int $window, ?Carbon $since): ?string
    {
        if ($window <= 0 || ! self::hasTable('agent_runs') || ! self::hasTable('agent_run_steps')) {
            return null;
        }

        $runs = AgentRun::forTenant($tenantId)->where('skill_slug', $skillSlug)->orderByDesc('created_at');
        if ($since !== null) {
            $runs->where('created_at', '>', $since);
        }
        $runIds = $runs->limit($window)->pluck('id')->all();
        if ($runIds === []) {
            return null;
        }

        $steps = AgentRunStep::whereIn('run_id', $runIds)->where('kind', AgentRunStep::KIND_VALIDATOR)->get(['status']);
        $total = $steps->count();
        if ($total < 5) {
            return null;
        }
        $rejected = $steps->filter(fn (AgentRunStep $s): bool => in_array($s->status, [AgentRunStep::STATUS_DENIED, AgentRunStep::STATUS_FAILED], true))->count();
        $observed = $rejected / $total;
        if ($observed >= $rate) {
            return sprintf('validator rejected %d of %d outputs (%d%%) for %s', $rejected, $total, (int) round($observed * 100), $skillSlug);
        }

        return null;
    }

    // ------------------------------------------------------------------ //
    //  State changes
    // ------------------------------------------------------------------ //

    private function trip(
        string $tenantId,
        string $scope,
        ?string $scopeId,
        string $state,
        string $reason,
        string $trippedBy,
        ?\DateTimeInterface $resumeAt,
        ?string $userId,
    ): TenantAgentState {
        $scope = $this->assertScope($scope);
        $scopeId = $this->normaliseScopeId($scope, $scopeId);
        $trippedBy = in_array($trippedBy, [TenantAgentState::TRIPPED_BY_HUMAN, TenantAgentState::TRIPPED_BY_TRIPWIRE], true)
            ? $trippedBy : TenantAgentState::TRIPPED_BY_HUMAN;

        $row = TenantAgentState::forTenant($tenantId)->where('scope', $scope)->where('scope_id', $scopeId)->first();
        $attributes = [
            'state' => $state,
            'reason' => mb_substr($reason, 0, 1000),
            'tripped_by' => $trippedBy,
            'tripped_by_user_id' => $userId,
            'tripped_at' => now(),
            'resume_at' => $resumeAt ? Carbon::instance($resumeAt) : null,
            'resumed_at' => null,
        ];

        if ($row === null) {
            $row = TenantAgentState::create(['tenant_id' => $tenantId, 'scope' => $scope, 'scope_id' => $scopeId] + $attributes);
        } else {
            // Pausing an already-demoted scope escalates; demoting a paused one never downgrades it.
            if ($row->state === TenantAgentState::STATE_PAUSED && $state === TenantAgentState::STATE_DEMOTED) {
                $attributes['state'] = TenantAgentState::STATE_PAUSED;
            }
            $row->fill($attributes)->save();
        }

        $this->record($row, 'agents.breaker.tripped');
        $this->notifyTripped($row);

        return $row->refresh();
    }

    private function markResumed(TenantAgentState $row, string $by): void
    {
        $previous = (array) $row->meta;
        $row->fill([
            'state' => TenantAgentState::STATE_RUNNING,
            'resumed_at' => now(),
            'resume_at' => null,
            'meta' => $previous + ['resumed_by' => $by],
        ])->save();

        if ($row->scope === TenantAgentState::SCOPE_SKILL && $row->scope_id !== null && isset($previous['previous_autonomy_level'])) {
            $this->restoreAutonomyRung($row->tenant_id, $row->scope_id, (string) $previous['previous_autonomy_level']);
        }

        $this->record($row, 'agents.breaker.resumed');
    }

    private function dropAutonomyRung(string $tenantId, string $skillSlug, TenantAgentState $state): void
    {
        if (! self::hasTable('tenant_skills')) {
            return;
        }
        $skill = TenantSkill::forTenant($tenantId)->where('skill_slug', $skillSlug)->first();
        if ($skill === null) {
            return;
        }

        $ladder = TenantSkill::LADDER;
        $current = (string) $skill->autonomy_level;
        $index = array_search($current, $ladder, true);
        $lower = match (true) {
            $current === TenantSkill::AUTONOMY_SHADOW => TenantSkill::AUTONOMY_HUMAN_LED,
            $index === false, $index === 0 => null,
            default => $ladder[$index - 1],
        };
        if ($lower === null) {
            return;
        }

        $skill->autonomy_level = $lower;
        $skill->save();

        $state->meta = ((array) $state->meta) + ['previous_autonomy_level' => $current, 'demoted_to' => $lower];
        $state->save();
    }

    private function restoreAutonomyRung(string $tenantId, string $skillSlug, string $level): void
    {
        if (! self::hasTable('tenant_skills') || ! in_array($level, TenantSkill::AUTONOMY_LEVELS, true)) {
            return;
        }
        TenantSkill::forTenant($tenantId)->where('skill_slug', $skillSlug)->update(['autonomy_level' => $level, 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private function matches(TenantAgentState $row, ?string $agentId, ?string $agentSlug, ?string $skillSlug, ?string $toolRisk): bool
    {
        return match ($row->scope) {
            TenantAgentState::SCOPE_TENANT => true,
            TenantAgentState::SCOPE_AGENT => $row->scope_id !== null && (
                ($agentId !== null && $row->scope_id === $agentId) || ($agentSlug !== null && $row->scope_id === $agentSlug)
            ),
            TenantAgentState::SCOPE_SKILL => $skillSlug !== null && $row->scope_id === $skillSlug,
            TenantAgentState::SCOPE_TOOL_RISK => $toolRisk !== null && $row->scope_id !== null
                && (self::RISK_ORDER[$toolRisk] ?? 0) >= (self::RISK_ORDER[$row->scope_id] ?? PHP_INT_MAX),
            default => false,
        };
    }

    private function describe(TenantAgentState $row): string
    {
        $what = match ($row->scope) {
            TenantAgentState::SCOPE_TENANT => 'everything',
            TenantAgentState::SCOPE_AGENT => "agent {$row->scope_id}",
            TenantAgentState::SCOPE_SKILL => "skill {$row->scope_id}",
            TenantAgentState::SCOPE_TOOL_RISK => "{$row->scope_id} tools",
            default => $row->scope,
        };
        $verb = $row->state === TenantAgentState::STATE_PAUSED ? 'paused' : 'demoted';
        $reason = trim((string) $row->reason);

        return ucfirst($verb).' '.$what.($reason !== '' ? ': '.$reason : '').' ('.$row->tripped_by.')';
    }

    private function agentSlug(string $tenantId, ?string $agentId): ?string
    {
        // agents.id is a uuid column; a slug or any other hint is matched directly in matches().
        if ($agentId === null || ! Str::isUuid($agentId) || ! self::hasTable('agents')) {
            return null;
        }
        try {
            $slug = DB::table('agents')->where('tenant_id', $tenantId)->where('id', $agentId)->value('slug');

            return is_string($slug) ? $slug : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function hasTable(string $table): bool
    {
        if (isset(self::$tables[$table])) {
            return true;
        }
        if (Schema::hasTable($table)) {
            self::$tables[$table] = true;

            return true;
        }

        return false;
    }

    private function assertScope(string $scope): string
    {
        if (! in_array($scope, TenantAgentState::SCOPES, true)) {
            throw new \InvalidArgumentException("Unknown breaker scope [{$scope}].");
        }

        return $scope;
    }

    private function normaliseScopeId(string $scope, ?string $scopeId): ?string
    {
        if ($scope === TenantAgentState::SCOPE_TENANT) {
            return null;
        }
        $scopeId = trim((string) $scopeId);
        if ($scopeId === '') {
            throw new \InvalidArgumentException("Breaker scope [{$scope}] needs a scope id.");
        }
        if ($scope === TenantAgentState::SCOPE_TOOL_RISK && ! isset(self::RISK_ORDER[$scopeId])) {
            throw new \InvalidArgumentException("Unknown tool risk [{$scopeId}].");
        }

        return $scopeId;
    }

    private function record(TenantAgentState $row, string $eventType): void
    {
        try {
            app(EventStore::class)->append(
                tenantId: $row->tenant_id,
                aggregateType: 'agent_breaker',
                aggregateId: $row->id,
                eventType: $eventType,
                payload: [
                    'scope' => $row->scope,
                    'scope_id' => $row->scope_id,
                    'state' => $row->state,
                    'reason' => $row->reason,
                    'tripped_by' => $row->tripped_by,
                    'resume_at' => $row->resume_at?->toIso8601String(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('agents.breaker.event_failed', ['error' => $e->getMessage()]);
        }
    }

    private function notifyTripped(TenantAgentState $row): void
    {
        try {
            app(NotificationService::class)->notifyTenantRole($row->tenant_id, ['admin', 'owner'], 'breaker_tripped', [
                'title' => $this->describe($row),
                'body' => $row->state === TenantAgentState::STATE_PAUSED
                    ? 'Nothing in this scope runs until you resume it.'
                    : 'Drafting continues; send and irreversible tools are blocked.',
                'url' => '/gods-eye',
                'scope' => $row->scope,
                'scope_id' => $row->scope_id,
                'state' => $row->state,
            ]);
        } catch (\Throwable $e) {
            Log::warning('agents.breaker.notify_failed', ['error' => $e->getMessage()]);
        }
    }
}
