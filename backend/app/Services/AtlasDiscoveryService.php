<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AtlasThread;
use App\Models\BrainFile;
use App\Models\Skill;
use App\Models\TenantSkill;
use App\Services\Founder\BrainWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Decides whether Atlas should ask discovery questions or act on a request,
 * and — plan D8 "one step further" — picks the one more question worth
 * asking on every turn (oneMoreQuestion()).
 */
class AtlasDiscoveryService
{
    /** Per-thread budget: at most this many one-more-questions a day. */
    public const ONE_STEP_MAX_PER_DAY = 5;

    /** Never on consecutive turns. */
    public const ONE_STEP_MIN_TURN_GAP = 2;

    /** "skip" buys this many quiet turns. */
    public const ONE_STEP_SKIP_COOLDOWN_TURNS = 3;

    /** A question asked (and unanswered) anywhere in the tenant within this window is not novel. */
    public const ONE_STEP_NOVELTY_DAYS = 7;

    public const ONE_STEP_USER_STEP_KEY = 'user_step';

    private const ONE_STEP_SKIP_PATTERN = '/^\W*(skip|later|not now|no thanks|nah|stop|maybe later|pass)\b/iu';

    private const ONE_STEP_ACK_PATTERN = '/^\W*(?:(?:ok(?:ay)?|thanks|thank you|thx|ty|got it|cool|great|sure|yes|yep|yeah|no|nope|fine|noted|nice|perfect|done|k|good|alright|understood|cheers|brilliant|lovely)\W*){1,3}$/iu';

    /** Tie-break order when candidates share a score (a blocked run beats a gap on the same section). */
    private const ONE_STEP_SOURCE_RANK = ['blocked_run' => 3, 'brain_gap' => 2, 'stale_section' => 1, 'profile' => 0];

    /** @var array<string, mixed>|null */
    private ?array $manifestCache = null;

    /** @var array<string, list<string>>|null path => skill slugs whose card requires it */
    private ?array $requirementsCache = null;

    /** @var array<string, float>|null skill slug => max replaces[] cost (USD/yr) */
    private ?array $replacesCostCache = null;

    private const VAGUE_PATTERNS = [
        'help me',
        'get started',
        'what should i',
        'where do i start',
        'automate',
        'not sure',
        'don\'t know',
        "don't know",
        'what do i need',
    ];

    /**
     * @return array{mode: string, questions?: array<int, string>, suggested_next?: array<string, mixed>, profile_pct?: int}
     */
    public function evaluate(string $tenantId, string $message, ?PackGrowthService $growth = null): array
    {
        $profile = $this->profileForTenant($tenantId);
        $pct = (int) ($profile['discovery_complete_pct'] ?? 0);
        $lower = strtolower(trim($message));

        $profileComplete = $pct >= 60 && ! $this->missingCriticalFields($profile);
        $shouldDiscover = ! $profileComplete
            || $this->matchesVaguePatterns($lower)
            || ($pct < 60 && strlen($lower) < 25);

        if (! $shouldDiscover) {
            return ['mode' => 'act', 'profile_pct' => $pct];
        }

        $questions = $this->nextQuestions($profile);
        $suggested = $this->suggestedNext($profile, $tenantId, $growth);

        return [
            'mode' => 'discover',
            'questions' => $questions,
            'suggested_next' => $suggested,
            'profile_pct' => $pct,
        ];
    }

    /**
     * Persist answers extracted from user messages (best-effort heuristics).
     */
    public function absorbAnswer(string $tenantId, string $message): void
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return;
        }

        $lower = strtolower($message);
        $updates = [];

        if (preg_match('/\b(solo|just me|1 person|myself)\b/', $lower)) {
            $updates['employee_count_band'] = 'solo';
        } elseif (preg_match('/\b(2-10|small team|few people)\b/', $lower)) {
            $updates['employee_count_band'] = '2-10';
        } elseif (preg_match('/\b(11-50|medium)\b/', $lower)) {
            $updates['employee_count_band'] = '11-50';
        }

        if (preg_match('/\b(invoice|invoic|billing customer|send bills)\b/', $lower)) {
            $updates['issues_invoices'] = true;
        }
        if (preg_match('/\b(email|customer data|personal data|pii|privacy)\b/', $lower)) {
            $updates['data_handles_pii'] = true;
        }
        if (preg_match('/\b(contractor|freelanc)\b/', $lower)) {
            $updates['hires_contractors'] = true;
        }

        if (preg_match('/\b(real estate|property|estate agency)\b/', $lower)) {
            $updates['industry'] = 'real_estate';
        } elseif (preg_match('/\b(retail|shop|store|ecommerce)\b/', $lower)) {
            $updates['industry'] = 'retail';
        } elseif (preg_match('/\b(consult|agency|professional service|law firm|accounting)\b/', $lower)) {
            $updates['industry'] = 'professional_services';
        } elseif (preg_match('/\b(health|clinic|medical|dental)\b/', $lower)) {
            $updates['industry'] = 'healthcare';
        } elseif (preg_match('/\b(construction|builder|trades)\b/', $lower)) {
            $updates['industry'] = 'construction';
        } elseif (preg_match('/\b(software|tech|saas)\b/', $lower)) {
            $updates['industry'] = 'technology';
        }

        if (strlen(trim($message)) > 20 && ! str_starts_with($lower, '/')) {
            $updates['biggest_time_drain'] = mb_substr(trim($message), 0, 500);
        }

        if (empty($updates)) {
            return;
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();
        if (! $row) {
            $updates['tenant_id'] = $tenantId;
            $updates['created_at'] = now();
            $updates['updated_at'] = now();
            $updates['discovery_complete_pct'] = $this->computePct(array_merge([
                'industry' => null,
                'employee_count_band' => null,
                'issues_invoices' => false,
                'data_handles_pii' => false,
                'biggest_time_drain' => null,
            ], $updates));
            DB::table('tenant_business_profiles')->insert($updates);

            return;
        }

        $merged = array_merge((array) $row, $updates);
        $updates['discovery_complete_pct'] = $this->computePct($merged);
        $updates['updated_at'] = now();
        DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->update($updates);
    }

    /**
     * @return array<string, mixed>
     */
    public function profileForTenant(string $tenantId): array
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return ['discovery_complete_pct' => 0];
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();

        return $row ? (array) $row : ['discovery_complete_pct' => 0];
    }

    private function matchesVaguePatterns(string $lower): bool
    {
        foreach (self::VAGUE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function missingCriticalFields(array $profile): bool
    {
        return empty($profile['employee_count_band'])
            && empty($profile['biggest_time_drain'])
            && empty($profile['industry']);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, string>
     */
    private function nextQuestions(array $profile): array
    {
        if (empty($profile['biggest_time_drain'])) {
            return ['What task eats the most time in your week?'];
        }
        if (empty($profile['employee_count_band'])) {
            return ['Are you working solo, or do you have a team?'];
        }
        if (! isset($profile['issues_invoices'])) {
            return ['Do you send invoices or quotes to customers?'];
        }

        return ['What would success look like if SpiderNetOS handled that for you?'];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function suggestedNext(array $profile, ?string $tenantId = null, ?PackGrowthService $growth = null): array
    {
        if ($tenantId && $growth) {
            $fromGrowth = $growth->suggestedNextForProfile($tenantId);
            if ($fromGrowth) {
                $growth->recordSignalThrottled($tenantId, 'atlas_suggested', $fromGrowth['pack'] ?? null, [
                    'relevance_score' => $fromGrowth['relevance_score'] ?? null,
                ]);

                return $fromGrowth;
            }
        }

        if (! empty($profile['issues_invoices'])) {
            return [
                'type' => 'automation',
                'label' => 'Chase overdue invoices',
                'pack' => 'financial-services',
                'path' => '/financial',
            ];
        }

        if (! empty($profile['biggest_time_drain']) && str_contains(strtolower((string) $profile['biggest_time_drain']), 'lead')) {
            return [
                'type' => 'automation',
                'label' => 'Capture and follow up on leads',
                'pack' => 'sales-crm',
                'path' => '/sales',
            ];
        }

        return [
            'type' => 'automation',
            'label' => 'Run your first automation in 5 minutes',
            'pack' => null,
            'path' => '/operate/first-win',
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function computePct(array $profile): int
    {
        $fields = ['industry', 'employee_count_band', 'biggest_time_drain'];
        $filled = 0;
        foreach ($fields as $field) {
            if (! empty($profile[$field])) {
                $filled++;
            }
        }
        if (! empty($profile['issues_invoices']) || ! empty($profile['data_handles_pii'])) {
            $filled++;
        }

        return (int) min(100, round(($filled / 4) * 100));
    }

    public function refreshCompletionPct(string $tenantId): void
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return;
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();
        if (! $row) {
            return;
        }

        DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->update([
            'discovery_complete_pct' => $this->computePct((array) $row),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ //
    //  One more question (plan D8 "one step further" mechanics)
    // ------------------------------------------------------------------ //

    /**
     * The one question worth asking on this turn, deterministically.
     *
     * Candidates: questions of blocked/waiting runs, BrainGapAnalyzer gaps
     * (when that class exists), manifest sections past stale_after_days,
     * legacy profile questions. score = unblock_value (3.0 skill matched on
     * this message, 2.0 skill blocked on it now, 1.0 enabled, 0.25
     * catalogue-only; tie-break by replaces[] cost) × recency (1.0 within
     * 24 h → 0.5 at 7 days) × novelty (0 if asked in this thread or
     * unanswered in 7 days). Per-thread budget and cooldowns; the answer
     * (mode=answer) writes to the brain section and stamps the thread.
     *
     * @param  object|null  $matchedSkill  SkillCard (or any object exposing slug / card) from SkillRegistry::matchIntent()
     * @param  string|null  $mode  chat (default) | answer (message answers the last question) | skip
     * @return array{question: string, key: string, source: string, path: ?string, section: ?string, run_id: ?string, skill: ?string, score: float, next_step: ?array, thread_id: ?string}|null
     */
    public function oneMoreQuestion(
        string $tenantId,
        ?string $userId,
        ?string $threadId,
        string $message,
        ?object $matchedSkill = null,
        ?string $mode = null,
    ): ?array {
        $thread = $this->threadFor($tenantId, $threadId);
        $state = $thread ? (array) ($thread->one_more_question_state ?? []) : [];
        $turn = (int) ($state['turns'] ?? 0) + 1;
        $state['turns'] = $turn;
        $trimmed = trim($message);
        $today = now()->toDateString();
        $matchedSlug = $this->skillSlugOf($matchedSkill);

        // "skip / later / stop" → quiet for a few turns.
        if ($mode === 'skip' || ($trimmed !== '' && preg_match(self::ONE_STEP_SKIP_PATTERN, $trimmed))) {
            $state = $this->stampLastAsked($state, 'skipped_at');
            $state['cooldown_until_turn'] = $turn + self::ONE_STEP_SKIP_COOLDOWN_TURNS;
            $this->saveOneStepState($thread, $state);

            return null;
        }

        // An inline answer to the last question → write it where it belongs; no new question this turn.
        if ($mode === 'answer' && ! empty($state['last_asked']) && $trimmed !== '') {
            $this->absorbOneStepAnswer($tenantId, $thread, $state, $trimmed);
            $state = $this->stampLastAsked($state, 'answered_at');
            $state['cooldown_until_turn'] = $turn + 1;
            $this->saveOneStepState($thread, $state);

            return null;
        }

        // Slash commands and acknowledgements never get a question.
        if ($trimmed !== '' && (str_starts_with($trimmed, '/') || preg_match(self::ONE_STEP_ACK_PATTERN, $trimmed))) {
            $this->saveOneStepState($thread, $state);

            return null;
        }

        // Budget + cooldowns (only meaningful inside a thread).
        if ($thread !== null) {
            $quiet = ((int) ($state['cooldown_until_turn'] ?? 0)) > $turn
                || (isset($state['last_asked_turn']) && $turn - (int) $state['last_asked_turn'] < self::ONE_STEP_MIN_TURN_GAP)
                || ((int) ($state['asked_dates'][$today] ?? 0)) >= self::ONE_STEP_MAX_PER_DAY;
            if ($quiet) {
                $this->saveOneStepState($thread, $state);

                return null;
            }
        }

        $recentElsewhere = $this->recentlyAskedElsewhere($tenantId, $thread?->id);
        $candidates = $this->oneStepCandidates($tenantId, $matchedSlug, $state, $recentElsewhere);
        $best = $candidates[0] ?? null;
        $nextStep = $this->nextStepFor($tenantId, $thread, $matchedSlug, $matchedSkill);

        // D0 (f): when no next step can be established and nothing urgent is
        // blocked, ask the user what one more step could be taken.
        if ($nextStep === null && ($best === null || $best['score'] < 2.0)) {
            $userStepNovel = ! isset($state['asked'][self::ONE_STEP_USER_STEP_KEY]) && ! isset($recentElsewhere[self::ONE_STEP_USER_STEP_KEY]);
            if ($userStepNovel) {
                $best = [
                    'key' => self::ONE_STEP_USER_STEP_KEY,
                    'question' => AtlasPromptStack::USER_STEP_QUESTION,
                    'source' => 'user_step',
                    'path' => null,
                    'section' => null,
                    'run_id' => null,
                    'skill' => $matchedSlug,
                    'score' => 1.0,
                ];
            }
        }

        if ($best === null) {
            $this->saveOneStepState($thread, $state);

            return null;
        }

        $question = $this->withSkipSuffix((string) $best['question']);
        $askedAt = now()->toIso8601String();
        $entry = (array) ($state['asked'][$best['key']] ?? []);
        $state['asked'][$best['key']] = [
            'asked_at' => $askedAt,
            'count' => (int) ($entry['count'] ?? 0) + 1,
            'source' => $best['source'],
            'path' => $best['path'],
            'section' => $best['section'],
            'run_id' => $best['run_id'],
            'skill' => $best['skill'],
            'question' => $question,
        ];
        $state['last_asked'] = $best['key'];
        $state['last_asked_turn'] = $turn;
        $state['asked_dates'] = [$today => (int) ($state['asked_dates'][$today] ?? 0) + 1];

        if ($thread !== null) {
            $open = array_values(array_filter((array) $thread->open_questions, fn ($q) => is_array($q) && ($q['key'] ?? null) !== $best['key']));
            $open[] = ['key' => $best['key'], 'question' => $question, 'source' => $best['source'], 'path' => $best['path'], 'section' => $best['section'], 'asked_at' => $askedAt];
            $thread->open_questions = array_slice($open, -20);
        }
        $this->saveOneStepState($thread, $state);

        return [
            'question' => $question,
            'key' => $best['key'],
            'source' => $best['source'],
            'path' => $best['path'],
            'section' => $best['section'],
            'run_id' => $best['run_id'],
            'skill' => $best['skill'],
            'score' => round((float) $best['score'], 4),
            'next_step' => $nextStep,
            'thread_id' => $thread?->id,
        ];
    }

    /**
     * Scored, sorted candidates (highest first). Exposed for tests and the brief.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, true>  $recentElsewhere
     * @return list<array<string, mixed>>
     */
    public function oneStepCandidates(string $tenantId, ?string $matchedSlug, array $state = [], array $recentElsewhere = []): array
    {
        $out = [];
        $push = function (array $c) use (&$out, $state, $recentElsewhere): void {
            $novelty = isset($state['asked'][$c['key']]) || isset($recentElsewhere[$c['key']]) ? 0.0 : 1.0;
            $c['novelty'] = $novelty;
            $c['score'] = $c['unblock'] * $c['recency'] * $novelty;
            $c += ['path' => null, 'section' => null, 'run_id' => null, 'skill' => null, 'tie' => 0.0];
            if ($c['score'] > 0) {
                $out[] = $c;
            }
        };

        // (a) Questions of blocked / waiting-input runs — a skill is blocked on them now.
        if (Schema::hasTable('agent_runs')) {
            $runs = AgentRun::forTenant($tenantId)
                ->whereIn('status', [AgentRun::STATUS_BLOCKED, AgentRun::STATUS_WAITING_INPUT])
                ->orderByDesc('updated_at')->limit(20)->get();
            foreach ($runs as $run) {
                foreach ((array) $run->questions as $i => $q) {
                    $text = is_array($q) ? trim((string) ($q['question'] ?? '')) : trim((string) $q);
                    if ($text === '') {
                        continue;
                    }
                    $path = is_array($q) ? ($q['path'] ?? null) : null;
                    $section = is_array($q) ? ($q['section'] ?? null) : null;
                    $push([
                        'key' => 'run:'.($path ? $path.'#'.(string) $section : $run->id.':'.$i),
                        'question' => $text,
                        'source' => 'blocked_run',
                        'path' => $path,
                        'section' => $section,
                        'run_id' => (string) $run->id,
                        'skill' => $run->skill_slug,
                        'unblock' => $matchedSlug !== null && $run->skill_slug === $matchedSlug ? 3.0 : 2.0,
                        'recency' => $this->recency($run->updated_at ?? $run->created_at),
                        'tie' => $this->replacesCost((string) $run->skill_slug),
                    ]);
                }
            }
        }

        // (b) Knowledge-brain gaps (BrainGapAnalyzer, when the class exists).
        foreach ($this->brainGaps($tenantId) as $gap) {
            $path = (string) ($gap['path'] ?? '');
            $section = (string) ($gap['section'] ?? '');
            $push([
                'key' => 'gap:'.$path.'#'.$section,
                'question' => (string) $gap['question'],
                'source' => 'brain_gap',
                'path' => $path !== '' ? $path : null,
                'section' => $section !== '' ? $section : null,
                'skill' => $this->bestSkillFor($path, $matchedSlug, $tenantId),
                'unblock' => $this->unblockValue($path, $matchedSlug, $tenantId),
                'recency' => 1.0,
                'tie' => $this->maxReplacesCostFor($path),
            ]);
        }

        // (c) Sections past their manifest stale_after_days.
        foreach ($this->staleFiles($tenantId) as $stale) {
            $push([
                'key' => 'stale:'.$stale['path'],
                'question' => sprintf('It has been %d days since "%s" was updated — is it still current, and what changed?', $stale['days'], $stale['title']),
                'source' => 'stale_section',
                'path' => $stale['path'],
                'section' => null,
                'skill' => $this->bestSkillFor($stale['path'], $matchedSlug, $tenantId),
                'unblock' => $this->unblockValue($stale['path'], $matchedSlug, $tenantId),
                'recency' => $this->recency($stale['stale_since']),
                'tie' => $this->maxReplacesCostFor($stale['path']),
            ]);
        }

        // (d) Legacy profile questions (tenant_business_profiles).
        $profile = $this->profileForTenant($tenantId);
        if ((int) ($profile['discovery_complete_pct'] ?? 0) < 100) {
            $question = $this->nextQuestions($profile)[0] ?? null;
            if ($question !== null) {
                $push([
                    'key' => 'profile:'.substr(md5($question), 0, 12),
                    'question' => $question,
                    'source' => 'profile',
                    'unblock' => $this->missingCriticalFields($profile) ? 1.0 : 0.25,
                    'recency' => 1.0,
                ]);
            }
        }

        // One question per brain section: a run blocked on offer/offer.md#Pricing
        // and the gap analyzer's entry for the same section are the same ask —
        // keep the more concrete source (the run carries a run_id).
        $byTopic = [];
        foreach ($out as $i => $c) {
            if (empty($c['path']) || ! in_array($c['source'], ['blocked_run', 'brain_gap'], true)) {
                continue;
            }
            $topic = $c['path'].'#'.(string) ($c['section'] ?? '');
            if (! isset($byTopic[$topic])) {
                $byTopic[$topic] = $i;

                continue;
            }
            $j = $byTopic[$topic];
            $keepNew = [self::ONE_STEP_SOURCE_RANK[$c['source']] ?? 0, $c['score']] > [self::ONE_STEP_SOURCE_RANK[$out[$j]['source']] ?? 0, $out[$j]['score']];
            unset($out[$keepNew ? $j : $i]);
            if ($keepNew) {
                $byTopic[$topic] = $i;
            }
        }
        $out = array_values($out);

        usort($out, fn (array $a, array $b): int => [
            $b['score'], self::ONE_STEP_SOURCE_RANK[$b['source']] ?? 0, $b['tie'], $a['key'],
        ] <=> [
            $a['score'], self::ONE_STEP_SOURCE_RANK[$a['source']] ?? 0, $a['tie'], $b['key'],
        ]);

        return $out;
    }

    /**
     * The next step to propose: the thread's proposed steps first, then
     * recent runs' outputs.next_steps[], then the matched card's
     * one_step_further.steps.
     *
     * @return array<string, mixed>|null
     */
    public function nextStepFor(string $tenantId, ?AtlasThread $thread, ?string $matchedSlug, ?object $matchedSkill = null): ?array
    {
        if ($thread !== null) {
            foreach ((array) $thread->last_next_steps as $step) {
                if (is_array($step) && ($step['state'] ?? 'proposed') === 'proposed' && ! empty($step['label'])) {
                    return $this->normaliseStep($step, (string) ($step['origin'] ?? 'model'), $step['run_id'] ?? null);
                }
            }
        }

        if (Schema::hasTable('agent_runs')) {
            $spawned = $thread ? array_values(array_filter((array) $thread->spawned_run_ids, 'is_string')) : [];
            $queries = [];
            if ($spawned !== []) {
                $queries[] = AgentRun::forTenant($tenantId)->whereIn('id', $spawned)->orderByDesc('finished_at')->limit(10);
            }
            $queries[] = AgentRun::forTenant($tenantId)->where('status', AgentRun::STATUS_SUCCEEDED)
                ->where('finished_at', '>=', now()->subDay())->orderByDesc('finished_at')->limit(10);
            foreach ($queries as $query) {
                foreach ($query->get() as $run) {
                    foreach ($run->nextSteps() as $step) {
                        if (is_array($step) && ($step['state'] ?? 'proposed') === 'proposed' && ! empty($step['label'])) {
                            return $this->normaliseStep($step, (string) ($step['origin'] ?? 'card'), (string) $run->id);
                        }
                    }
                }
            }
        }

        $card = $this->cardOf($matchedSkill, $matchedSlug);
        foreach ((array) ($card['one_step_further']['steps'] ?? []) as $step) {
            if (is_array($step) && in_array($step['when'] ?? 'always', ['always', 'on_success'], true) && ! empty($step['label'])) {
                return $this->normaliseStep($step, 'card', null);
            }
        }

        return null;
    }

    // ---- candidates: sources ------------------------------------------ //

    /**
     * Knowledge-brain gaps from BrainGapAnalyzer (Stream A): the manifest
     * readiness view (one ask per file, manifest order) plus the gaps of
     * every enabled skill's `brain.requires` (via SkillRegistry, Stream B1).
     *
     * @return list<array{path: string, section: ?string, question: string}>
     */
    private function brainGaps(string $tenantId): array
    {
        if (! class_exists('App\\Services\\Brain\\BrainGapAnalyzer')) {
            return [];
        }

        $out = [];
        $seen = [];
        $add = function (array $g) use (&$out, &$seen): void {
            $path = (string) ($g['path'] ?? '');
            $section = isset($g['section']) && $g['section'] !== '' ? (string) $g['section'] : null;
            $question = trim((string) ($g['question'] ?? ''));
            $key = $path.'#'.(string) $section;
            if ($path === '' || $question === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['path' => $path, 'section' => $section, 'question' => $question];
        };

        try {
            $analyzer = app('App\\Services\\Brain\\BrainGapAnalyzer');

            foreach ((array) ($analyzer->readiness($tenantId)['files'] ?? []) as $file) {
                if (is_array($file) && ! empty($file['ask_prompt'])) {
                    $add(['path' => $file['path'] ?? '', 'section' => $file['ask_section'] ?? null, 'question' => $file['ask_prompt']]);
                }
            }

            $requires = $this->enabledSkillRequires($tenantId);
            if ($requires !== []) {
                foreach ((array) $analyzer->gaps($tenantId, $requires) as $g) {
                    if (is_array($g)) {
                        $add($g);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('atlas.one_step.gaps_unavailable', ['error' => $e->getMessage()]);
        }

        return array_slice($out, 0, 20);
    }

    /**
     * Union of `brain.requires` across the tenant's enabled skills (cards from
     * SkillRegistry when present, else the seeded skills table).
     *
     * @return list<mixed>
     */
    private function enabledSkillRequires(string $tenantId): array
    {
        $requires = [];
        foreach ($this->enabledSkillSlugs($tenantId) as $slug) {
            $card = null;
            if (class_exists('App\\Services\\Skills\\SkillRegistry')) {
                try {
                    $card = app('App\\Services\\Skills\\SkillRegistry')->get($slug);
                } catch (\Throwable) {
                    $card = null;
                }
            }
            $list = $card !== null ? (array) ($card->brain['requires'] ?? []) : (array) ($this->cardOf(null, $slug)['brain']['requires'] ?? []);
            foreach ($list as $req) {
                $requires[] = $req;
            }
        }

        return $requires;
    }

    /** @return list<array{path: string, title: string, days: int, stale_since: Carbon}> */
    private function staleFiles(string $tenantId): array
    {
        if (! Schema::hasTable('brain_files')) {
            return [];
        }
        $rules = [];
        foreach ((array) ($this->manifest()['paths'] ?? []) as $entry) {
            if (is_array($entry) && ! empty($entry['path']) && ! empty($entry['stale_after_days']) && ! str_contains((string) $entry['path'], '<')) {
                $rules[(string) $entry['path']] = ['days' => (int) $entry['stale_after_days'], 'title' => (string) ($entry['title'] ?? BrainWriter::titleFromPath((string) $entry['path']))];
            }
        }
        if ($rules === []) {
            return [];
        }

        $out = [];
        foreach (BrainFile::forTenant($tenantId)->whereIn('path', array_keys($rules))->get(['path', 'title', 'updated_at']) as $file) {
            $rule = $rules[$file->path];
            $updated = $file->updated_at ? Carbon::instance($file->updated_at) : null;
            if ($updated === null) {
                continue;
            }
            $staleSince = $updated->copy()->addDays($rule['days']);
            if ($staleSince->isFuture()) {
                continue;
            }
            $out[] = [
                'path' => (string) $file->path,
                'title' => (string) ($file->title ?: $rule['title']),
                'days' => (int) floor((float) $updated->diffInDays(now())),
                'stale_since' => $staleSince,
            ];
        }

        return $out;
    }

    // ---- scoring ------------------------------------------------------- //

    /** 1.0 within 24 h → 0.5 at 7 days (linear), 1.0 when unknown. */
    public function recency(?\DateTimeInterface $at): float
    {
        if ($at === null) {
            return 1.0;
        }
        $days = max(0.0, (float) Carbon::instance($at)->diffInSeconds(now())) / 86400;
        if ($days <= 1) {
            return 1.0;
        }
        if ($days >= 7) {
            return 0.5;
        }

        return round(1.0 - 0.5 * ($days - 1) / 6, 4);
    }

    private function unblockValue(string $path, ?string $matchedSlug, string $tenantId): float
    {
        $slugs = $this->skillsRequiring($path);
        if ($matchedSlug !== null && in_array($matchedSlug, $slugs, true)) {
            return 3.0;
        }
        if (array_intersect($slugs, $this->enabledSkillSlugs($tenantId)) !== []) {
            return 1.0;
        }

        return 0.25;
    }

    private function bestSkillFor(string $path, ?string $matchedSlug, string $tenantId): ?string
    {
        $slugs = $this->skillsRequiring($path);
        if ($matchedSlug !== null && in_array($matchedSlug, $slugs, true)) {
            return $matchedSlug;
        }
        $enabled = array_values(array_intersect($slugs, $this->enabledSkillSlugs($tenantId)));

        return $enabled[0] ?? ($slugs[0] ?? null);
    }

    /** @return list<string> */
    private function skillsRequiring(string $path): array
    {
        $path = (string) preg_replace('/#.*$/', '', $path);

        return $this->brainRequirements()[$path] ?? [];
    }

    /** @return array<string, list<string>> path => slugs (card brain.requires[], keys resolved via the manifest) */
    private function brainRequirements(): array
    {
        if ($this->requirementsCache !== null) {
            return $this->requirementsCache;
        }
        $map = [];
        if (Schema::hasTable('skills')) {
            $keys = (array) ($this->manifest()['keys'] ?? []);
            foreach (Skill::query()->get(['slug', 'card']) as $skill) {
                foreach ((array) (($skill->card ?? [])['brain']['requires'] ?? []) as $req) {
                    $path = null;
                    if (is_array($req)) {
                        $path = $req['path'] ?? (isset($req['key'], $keys[$req['key']]) ? (string) $keys[$req['key']] : null);
                    } elseif (is_string($req)) {
                        $path = $keys[$req] ?? $req;
                    }
                    if ($path === null) {
                        continue;
                    }
                    $path = (string) preg_replace('/#.*$/', '', (string) $path);
                    $map[$path][] = (string) $skill->slug;
                }
            }
        }
        foreach ($map as $p => $slugs) {
            $map[$p] = array_values(array_unique($slugs));
        }

        return $this->requirementsCache = $map;
    }

    /** @return list<string> */
    private function enabledSkillSlugs(string $tenantId): array
    {
        if (! Schema::hasTable('tenant_skills')) {
            return [];
        }

        return TenantSkill::forTenant($tenantId)->enabled()->pluck('skill_slug')->map(fn ($s) => (string) $s)->all();
    }

    private function maxReplacesCostFor(string $path): float
    {
        $max = 0.0;
        foreach ($this->skillsRequiring($path) as $slug) {
            $max = max($max, $this->replacesCost($slug));
        }

        return $max;
    }

    /** Max yearly USD parsed from the card's replaces[].cost bands ("$60–80k/yr" → 80000, "$2–4k/mo" → 48000). */
    public function replacesCost(string $slug): float
    {
        if ($this->replacesCostCache === null) {
            $this->replacesCostCache = [];
            if (Schema::hasTable('skill_relations')) {
                foreach (DB::table('skill_relations')->where('relation', 'replaces')->get(['from_slug', 'meta']) as $row) {
                    $meta = json_decode((string) $row->meta, true) ?: [];
                    $cost = self::parseCost((string) ($meta['cost'] ?? ''));
                    $this->replacesCostCache[$row->from_slug] = max($this->replacesCostCache[$row->from_slug] ?? 0.0, $cost);
                }
            }
        }

        return $this->replacesCostCache[$slug] ?? 0.0;
    }

    public static function parseCost(string $band): float
    {
        if (! preg_match_all('/(\d+(?:\.\d+)?)\s*(k|m)?/i', $band, $m, PREG_SET_ORDER)) {
            return 0.0;
        }
        $max = 0.0;
        foreach ($m as $hit) {
            $n = (float) $hit[1];
            $unit = strtolower($hit[2] ?? '');
            $n *= $unit === 'k' ? 1000 : ($unit === 'm' ? 1000000 : 1);
            $max = max($max, $n);
        }
        if (preg_match('/\/\s*mo|per month|monthly/i', $band)) {
            $max *= 12;
        }

        return $max;
    }

    // ---- thread state -------------------------------------------------- //

    private function threadFor(string $tenantId, ?string $threadId): ?AtlasThread
    {
        if ($threadId === null || $threadId === '' || ! Schema::hasTable('atlas_threads')) {
            return null;
        }

        return AtlasThread::forTenant($tenantId)->find($threadId);
    }

    /**
     * Keys asked (and not answered) in the tenant's other threads within the
     * novelty window.
     *
     * @return array<string, true>
     */
    private function recentlyAskedElsewhere(string $tenantId, ?string $threadId): array
    {
        if (! Schema::hasTable('atlas_threads')) {
            return [];
        }
        $since = now()->subDays(self::ONE_STEP_NOVELTY_DAYS);
        $query = AtlasThread::forTenant($tenantId)->where('updated_at', '>=', $since)->latestFirst()->limit(50);
        if ($threadId !== null) {
            $query->where('id', '!=', $threadId);
        }

        $keys = [];
        foreach ($query->get(['id', 'one_more_question_state']) as $t) {
            foreach ((array) (($t->one_more_question_state ?? [])['asked'] ?? []) as $key => $entry) {
                if (! is_array($entry) || ! empty($entry['answered_at']) || empty($entry['asked_at'])) {
                    continue;
                }
                try {
                    if (Carbon::parse((string) $entry['asked_at'])->gte($since)) {
                        $keys[(string) $key] = true;
                    }
                } catch (\Throwable) {
                    // ignore malformed stamps
                }
            }
        }

        return $keys;
    }

    /** @param array<string, mixed> $state */
    private function stampLastAsked(array $state, string $field): array
    {
        $key = $state['last_asked'] ?? null;
        if ($key !== null && isset($state['asked'][$key])) {
            $state['asked'][$key][$field] = now()->toIso8601String();
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function saveOneStepState(?AtlasThread $thread, array $state): void
    {
        if ($thread === null) {
            return;
        }
        $thread->one_more_question_state = $state;
        $thread->last_seen_at = now();
        $thread->save();
    }

    /**
     * Route an inline answer: a user-proposed next step joins the thread's
     * next steps (origin user); a brain question writes its section; the
     * legacy profile question feeds absorbAnswer().
     *
     * @param  array<string, mixed>  $state
     */
    private function absorbOneStepAnswer(string $tenantId, ?AtlasThread $thread, array $state, string $answer): void
    {
        $key = (string) ($state['last_asked'] ?? '');
        $entry = (array) ($state['asked'][$key] ?? []);
        $source = (string) ($entry['source'] ?? '');

        try {
            if ($source === 'user_step' || $key === self::ONE_STEP_USER_STEP_KEY) {
                if ($thread !== null) {
                    $steps = array_values((array) $thread->last_next_steps);
                    $steps[] = [
                        'id' => (string) Str::uuid(),
                        'origin' => 'user',
                        'label' => Str::limit($answer, 80, ''),
                        'does' => $answer,
                        'skill' => $entry['skill'] ?? null,
                        'inputs' => [],
                        'state' => 'proposed',
                        'proposed_at' => now()->toIso8601String(),
                    ];
                    $thread->last_next_steps = array_slice($steps, -20);
                }
            } elseif (! empty($entry['path'])) {
                app(BrainWriter::class)->upsertSection(
                    $tenantId,
                    (string) $entry['path'],
                    (string) ($entry['section'] ?: 'Notes'),
                    $answer,
                    [
                        'source' => 'human',
                        'author_type' => 'user',
                        'author_ref' => $thread?->user_id,
                        'change_summary' => 'Answered Atlas: '.Str::limit((string) ($entry['question'] ?? $key), 80),
                    ],
                );
            } else {
                $this->absorbAnswer($tenantId, $answer);
            }
        } catch (\Throwable $e) {
            Log::warning('atlas.one_step.answer_failed', ['key' => $key, 'error' => $e->getMessage()]);
        }

        if ($thread !== null) {
            $thread->open_questions = array_values(array_map(function ($q) use ($key) {
                if (is_array($q) && ($q['key'] ?? null) === $key) {
                    $q['answered_at'] = now()->toIso8601String();
                }

                return $q;
            }, (array) $thread->open_questions));
        }
    }

    // ---- helpers ------------------------------------------------------- //

    private function withSkipSuffix(string $question): string
    {
        $question = trim($question);
        if (str_contains(strtolower($question), 'or say skip')) {
            return $question;
        }

        return $question.' (or say skip)';
    }

    /** @return array<string, mixed> */
    private function normaliseStep(array $step, string $origin, ?string $runId): array
    {
        return [
            'id' => (string) ($step['id'] ?? Str::uuid()),
            'origin' => in_array($origin, ['card', 'model', 'user'], true) ? $origin : 'model',
            'label' => (string) ($step['label'] ?? ''),
            'does' => (string) ($step['does'] ?? $step['label'] ?? ''),
            'skill' => isset($step['skill']) ? (string) $step['skill'] : null,
            'inputs' => (array) ($step['inputs'] ?? $step['inputs_from'] ?? []),
            'risk' => $step['risk'] ?? null,
            'state' => (string) ($step['state'] ?? 'proposed'),
            'run_id' => $runId,
        ];
    }

    private function skillSlugOf(?object $skill): ?string
    {
        if ($skill === null) {
            return null;
        }
        foreach (['slug', 'id'] as $prop) {
            try {
                if (isset($skill->{$prop}) && is_string($skill->{$prop}) && $skill->{$prop} !== '') {
                    return $skill->{$prop};
                }
            } catch (\Throwable) {
                // inaccessible property
            }
        }
        if (method_exists($skill, 'slug')) {
            $v = $skill->slug();
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        $card = $this->cardOf($skill, null);

        return isset($card['slug']) && is_string($card['slug']) ? $card['slug'] : null;
    }

    /** @return array<string, mixed> */
    private function cardOf(?object $skill, ?string $slug): array
    {
        if ($skill !== null) {
            try {
                if (isset($skill->card) && is_array($skill->card)) {
                    return $skill->card;
                }
            } catch (\Throwable) {
                // ignore
            }
            foreach (['card', 'toArray'] as $method) {
                if (method_exists($skill, $method)) {
                    $v = $skill->{$method}();
                    if (is_array($v)) {
                        return $v;
                    }
                }
            }
        }
        if ($slug !== null && Schema::hasTable('skills')) {
            $row = Skill::find($slug);
            if ($row !== null) {
                return (array) ($row->card ?? []);
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        if ($this->manifestCache !== null) {
            return $this->manifestCache;
        }
        $file = (string) config('agents.brain_manifest', '');
        try {
            $this->manifestCache = $file !== '' && is_file($file) && class_exists(Yaml::class)
                ? (array) Yaml::parseFile($file)
                : [];
        } catch (\Throwable $e) {
            Log::info('atlas.one_step.manifest_unreadable', ['error' => $e->getMessage()]);
            $this->manifestCache = [];
        }

        return $this->manifestCache;
    }
}
