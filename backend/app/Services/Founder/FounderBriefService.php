<?php

declare(strict_types=1);

namespace App\Services\Founder;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AwarenessItem;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Services\AtlasDiscoveryService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Needs-You Today (plan D8 #3): a deterministic morning brief with at most
 * seven ranked items, one action each — pending approvals (age, money at
 * stake), runs blocked on a question, the highest-value brain gap, open
 * awareness items, actions inside their undo window, stale drafts, today's
 * calendar — plus what ran overnight and Atlas's one more question.
 *
 * No LLM in the ranking. Written to reports/daily/YYYY-MM-DD.md in the
 * Knowledge brain, served by GET /api/today, printed by `brief:today`.
 */
class FounderBriefService
{
    public const MAX_ITEMS = 7;

    public const KINDS = ['approval', 'blocked_run', 'brain_gap', 'awareness', 'undo_window', 'stale_draft', 'calendar', 'overnight'];

    /** Hour (tenant-local) after which yesterday's runs count as "overnight". */
    public const OVERNIGHT_FROM_HOUR = 18;

    private const MONEY_KEYS = ['amount', 'amount_usd', 'total', 'total_amount', 'total_usd', 'money', 'value_usd', 'cost_estimate', 'cost_usd', 'estimated_cost_usd', 'budget'];

    public function __construct(
        private readonly AtlasDiscoveryService $discovery,
        private readonly BrainWriter $writer,
    ) {}

    /**
     * @return array{date: string, tenant_id: string, timezone: string, generated_at: string, items: list<array<string, mixed>>, overnight: list<array<string, mixed>>, one_more_question: array<string, mixed>|null, counts: array<string, int>, markdown: string, path: string|null}
     */
    public function compose(string $tenantId, ?\DateTimeInterface $for = null): array
    {
        $tenant = Tenant::find($tenantId);
        $zone = self::timezoneOf($tenant);
        $now = ($for ? Carbon::instance($for) : now())->setTimezone($zone);
        if ($for !== null && ! ($for instanceof Carbon) && $now->format('H:i:s') === '00:00:00') {
            // A bare date means "the brief for that day" — rank as of 07:00 local.
            $now = $now->setTime(7, 0);
        }

        $candidates = array_merge(
            $this->approvalItems($tenantId, $now),
            $this->blockedRunItems($tenantId, $now),
            $this->brainGapItems($tenantId),
            $this->awarenessItems($tenantId, $now),
            $this->undoWindowItems($tenantId, $now),
            $this->staleDraftItems($tenantId, $now),
            $this->calendarItems($tenantId, $now),
        );

        usort($candidates, function (array $a, array $b): int {
            return [$b['score'], $a['sort_at'], $a['id']] <=> [$a['score'], $b['sort_at'], $b['id']];
        });

        $items = array_map(function (array $item, int $i): array {
            unset($item['sort_at']);
            $item['rank'] = $i + 1;

            return $item;
        }, array_slice($candidates, 0, self::MAX_ITEMS), array_keys(array_slice($candidates, 0, self::MAX_ITEMS)));

        $overnight = $this->overnight($tenantId, $now);
        $question = $this->oneMoreQuestion($tenantId);

        $counts = [];
        foreach ($candidates as $c) {
            $counts[$c['kind']] = ($counts[$c['kind']] ?? 0) + 1;
        }

        $brief = [
            'date' => $now->toDateString(),
            'tenant_id' => $tenantId,
            'timezone' => $zone,
            'generated_at' => now()->toIso8601String(),
            'items' => array_values($items),
            'overnight' => $overnight,
            'one_more_question' => $question,
            'counts' => $counts + ['total_candidates' => count($candidates)],
        ];
        $brief['markdown'] = $this->render($brief);
        $brief['path'] = $this->write($tenantId, $brief);

        return $brief;
    }

    /** Push brief_ready to the tenant's admins/owners (FounderBriefJob calls this inside the 06:55–07:05 window). */
    public function notifyReady(string $tenantId, array $brief): void
    {
        $items = $brief['items'] ?? [];
        $n = count($items);
        $titles = array_slice(array_map(fn (array $i): string => '• '.$i['title'], $items), 0, 3);

        app(NotificationService::class)->notifyTenantRole($tenantId, ['admin', 'owner'], 'brief_ready', [
            'title' => $n === 0 ? 'Needs-You Today: nothing waiting on you' : "Needs-You Today: {$n} item".($n === 1 ? '' : 's'),
            'body' => implode("\n", $titles),
            'url' => '/',
            'date' => $brief['date'] ?? now()->toDateString(),
            'path' => $brief['path'] ?? null,
            'count' => $n,
        ]);
    }

    public static function timezoneOf(?Tenant $tenant): string
    {
        $settings = (array) ($tenant?->settings ?? []);
        $zone = (string) (($settings['timezone'] ?? null) ?: (($settings['outreach']['sending']['timezone'] ?? null) ?: 'UTC'));
        try {
            new \DateTimeZone($zone);

            return $zone;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    // ------------------------------------------------------------------ //
    //  Sources
    // ------------------------------------------------------------------ //

    /** @return list<array<string, mixed>> */
    private function approvalItems(string $tenantId, Carbon $now): array
    {
        if (! Schema::hasTable('approvals')) {
            return [];
        }

        return Approval::forTenant($tenantId)->pending()->orderBy('requested_at')->limit(30)->get()
            ->map(function (Approval $a) use ($now): array {
                $context = (array) ($a->context ?? []);
                $money = $this->moneyFrom($context);
                $requestedAt = $a->requested_at ?? $a->created_at ?? $now;
                $ageHours = max(0, $requestedAt->diffInHours($now));
                $title = trim((string) ($context['title'] ?? $context['summary'] ?? $context['subject'] ?? $a->reason ?? ''));
                $title = $title !== '' ? Str::limit($title, 90) : Str::headline((string) $a->resource_type);

                return $this->item(
                    kind: 'approval',
                    id: (string) $a->id,
                    title: 'Approve: '.$title,
                    detail: Str::headline((string) $a->resource_type).' · waiting '.self::age($requestedAt, $now)
                        .($money !== null ? ' · '.self::money($money) : ''),
                    action: ['label' => 'Review', 'path' => '/approvals?focus='.$a->id],
                    score: 100 + min($ageHours, 72) / 72 * 10 + ($money !== null ? min(log10($money + 1) * 5, 15) : 0),
                    at: $requestedAt,
                    now: $now,
                    money: $money,
                    extra: ['resource_type' => $a->resource_type, 'resource_id' => $a->resource_id, 'expires_at' => $a->expires_at?->toIso8601String()],
                );
            })->all();
    }

    /** @return list<array<string, mixed>> */
    private function blockedRunItems(string $tenantId, Carbon $now): array
    {
        if (! Schema::hasTable('agent_runs')) {
            return [];
        }

        return AgentRun::forTenant($tenantId)
            ->whereIn('status', [AgentRun::STATUS_BLOCKED, AgentRun::STATUS_WAITING_INPUT])
            ->orderBy('updated_at')->limit(20)->get()
            ->map(function (AgentRun $run) use ($now): array {
                $first = self::firstQuestion($run);
                $at = $run->updated_at ?? $run->created_at ?? $now;

                return $this->item(
                    kind: 'blocked_run',
                    id: (string) $run->id,
                    title: Str::headline((string) $run->skill_slug).' is waiting on you',
                    detail: $first ?? 'The run needs your input to continue.',
                    action: ['label' => 'Answer', 'path' => '/agents/runs/'.$run->id],
                    score: 90 + min(max(0, $at->diffInHours($now)), 48) / 48 * 5,
                    at: $at,
                    now: $now,
                    extra: ['skill_slug' => $run->skill_slug, 'status' => $run->status, 'question' => $first],
                );
            })->all();
    }

    /** The single highest-value brain gap (BrainGapAnalyzer::readiness, manifest order) — skipped when the class is absent. */
    private function brainGapItems(string $tenantId): array
    {
        $gap = null;
        if (class_exists('App\\Services\\Brain\\BrainGapAnalyzer')) {
            try {
                $readiness = app('App\\Services\\Brain\\BrainGapAnalyzer')->readiness($tenantId);
                foreach ((array) ($readiness['files'] ?? []) as $file) {
                    if (is_array($file) && ! empty($file['ask_prompt'])) {
                        $gap = ['path' => (string) ($file['path'] ?? ''), 'section' => (string) ($file['ask_section'] ?? ''), 'question' => (string) $file['ask_prompt']];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::info('founder.brief.gaps_unavailable', ['error' => $e->getMessage()]);
            }
        }

        if ($gap === null) {
            return [];
        }

        $path = (string) ($gap['path'] ?? '');
        $section = (string) ($gap['section'] ?? '');

        return [$this->item(
            kind: 'brain_gap',
            id: 'gap:'.$path.'#'.$section,
            title: 'Fill the gap: '.($section !== '' ? $section : BrainWriter::titleFromPath($path)),
            detail: (string) $gap['question'],
            action: ['label' => 'Fill in', 'path' => '/brain?path='.rawurlencode($path)],
            score: 60,
            at: null,
            now: now(),
            extra: ['path' => $path, 'section' => $section],
        )];
    }

    /** @return list<array<string, mixed>> */
    private function awarenessItems(string $tenantId, Carbon $now): array
    {
        if (! Schema::hasTable('awareness_items')) {
            return [];
        }

        return AwarenessItem::forTenant($tenantId)->open()->orderBy('created_at')->limit(20)->get()
            ->map(function (AwarenessItem $a) use ($now): array {
                $score = match ((string) $a->severity) {
                    'critical' => 95,
                    'warning' => 70,
                    default => 40,
                };

                return $this->item(
                    kind: 'awareness',
                    id: (string) $a->id,
                    title: (string) $a->title,
                    detail: trim((string) ($a->detail ?? '')) !== '' ? Str::limit((string) $a->detail, 160) : Str::headline((string) $a->severity).' · '.Str::headline((string) $a->source),
                    action: ['label' => 'Acknowledge', 'path' => '/operating'],
                    score: $score,
                    at: $a->created_at,
                    now: $now,
                    extra: ['severity' => $a->severity, 'source' => $a->source, 'status' => $a->status],
                );
            })->all();
    }

    /** Undo ledger lands in PR 2 (plan D8 #5); nothing to rank until then. */
    private function undoWindowItems(string $tenantId, Carbon $now): array
    {
        return [];
    }

    /** Drafts older than 24 h, grouped when there are several. */
    private function staleDraftItems(string $tenantId, Carbon $now): array
    {
        if (! Schema::hasTable('conversation_messages')) {
            return [];
        }

        $drafts = ConversationMessage::forTenant($tenantId)->where('status', 'draft')
            ->where('created_at', '<', $now->copy()->subDay())
            ->orderBy('created_at')->limit(20)->get();
        if ($drafts->isEmpty()) {
            return [];
        }

        $oldest = $drafts->first();
        if ($drafts->count() === 1) {
            $excerpt = trim((string) ($oldest->subject ?: Str::limit((string) $oldest->body, 80)));

            return [$this->item(
                kind: 'stale_draft',
                id: (string) $oldest->id,
                title: 'A draft has waited '.self::age($oldest->created_at, $now),
                detail: $excerpt !== '' ? $excerpt : 'Reply draft awaiting review.',
                action: ['label' => 'Review draft', 'path' => '/sales/partners/dm-queue'],
                score: 50 + min($oldest->created_at->diffInHours($now), 168) / 168 * 10,
                at: $oldest->created_at,
                now: $now,
                extra: ['message_id' => $oldest->id],
            )];
        }

        return [$this->item(
            kind: 'stale_draft',
            id: 'stale-drafts:'.$oldest->id,
            title: $drafts->count().' drafts have waited more than a day',
            detail: 'Oldest: '.self::age($oldest->created_at, $now).'. They send nothing until you look.',
            action: ['label' => 'Review drafts', 'path' => '/sales/partners/dm-queue'],
            score: 55 + min($oldest->created_at->diffInHours($now), 168) / 168 * 10,
            at: $oldest->created_at,
            now: $now,
            extra: ['count' => $drafts->count(), 'message_ids' => $drafts->pluck('id')->all()],
        )];
    }

    /** No calendar adapter is wired into the brief yet (meeting-lifecycle card, PR 2). */
    private function calendarItems(string $tenantId, Carbon $now): array
    {
        return [];
    }

    /**
     * Runs that finished and artifacts produced since yesterday 18:00 local.
     *
     * @return list<array<string, mixed>>
     */
    private function overnight(string $tenantId, Carbon $now): array
    {
        $since = $now->copy()->subDay()->setTime(self::OVERNIGHT_FROM_HOUR, 0);
        $out = [];

        if (Schema::hasTable('agent_runs')) {
            $runs = AgentRun::forTenant($tenantId)->whereIn('status', AgentRun::TERMINAL)
                ->where('finished_at', '>=', $since)->orderByDesc('finished_at')->limit(20)->get();
            foreach ($runs as $run) {
                $out[] = [
                    'kind' => 'run',
                    'id' => (string) $run->id,
                    'skill_slug' => $run->skill_slug,
                    'status' => $run->status,
                    'title' => Str::headline((string) $run->skill_slug).' '.($run->status === AgentRun::STATUS_SUCCEEDED ? 'finished' : $run->status),
                    'artifacts' => Schema::hasTable('agent_artifacts') ? $run->artifacts()->count() : 0,
                    'next_steps' => count($run->nextSteps()),
                    'cost_usd' => (float) $run->cost_usd,
                    'at' => $run->finished_at?->toIso8601String(),
                    'path' => '/agents/runs/'.$run->id,
                ];
            }
        }

        if (Schema::hasTable('agent_artifacts')) {
            $artifacts = AgentArtifact::forTenant($tenantId)->where('created_at', '>=', $since)
                ->orderByDesc('created_at')->limit(20)->get();
            foreach ($artifacts as $artifact) {
                $out[] = [
                    'kind' => 'artifact',
                    'id' => (string) $artifact->id,
                    'artifact_kind' => $artifact->kind,
                    'skill_slug' => $artifact->skill_slug,
                    'status' => $artifact->status,
                    'title' => (string) ($artifact->title ?: Str::headline((string) $artifact->kind)),
                    'at' => $artifact->created_at?->toIso8601String(),
                    'path' => $artifact->run_id ? '/agents/runs/'.$artifact->run_id : '/agents/runs',
                ];
            }
        }

        usort($out, fn (array $a, array $b): int => [$b['at'] ?? '', $a['id']] <=> [$a['at'] ?? '', $b['id']]);

        return array_slice($out, 0, 25);
    }

    /** @return array<string, mixed>|null */
    private function oneMoreQuestion(string $tenantId): ?array
    {
        if (! method_exists($this->discovery, 'oneMoreQuestion')) {
            return null;
        }
        try {
            $q = $this->discovery->oneMoreQuestion($tenantId, null, null, '', null);

            return is_array($q) && ! empty($q['question']) ? $q : null;
        } catch (\Throwable $e) {
            Log::info('founder.brief.one_more_question_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    // ------------------------------------------------------------------ //
    //  Rendering + persistence
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $brief */
    public function render(array $brief): string
    {
        $lines = ['# Needs-You Today — '.$brief['date'], ''];

        if (($brief['items'] ?? []) === []) {
            $lines[] = 'Nothing is waiting on you. Enjoy the quiet.';
        } else {
            foreach ($brief['items'] as $i => $item) {
                $meta = [];
                if (! empty($item['age'])) {
                    $meta[] = $item['age'];
                }
                if (isset($item['money']) && $item['money'] !== null) {
                    $meta[] = self::money((float) $item['money']);
                }
                $lines[] = sprintf(
                    '%d. **%s** — %s%s → [%s](%s)',
                    $i + 1,
                    $item['title'],
                    $item['detail'],
                    $meta !== [] ? ' _('.implode(' · ', $meta).')_' : '',
                    $item['action']['label'],
                    $item['action']['path'],
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Overnight';
        if (($brief['overnight'] ?? []) === []) {
            $lines[] = '- Nothing ran overnight.';
        } else {
            foreach ($brief['overnight'] as $o) {
                $lines[] = '- '.$o['title'].($o['at'] ? ' ('.substr((string) $o['at'], 11, 5).')' : '').' → '.$o['path'];
            }
        }

        if (! empty($brief['one_more_question']['question'])) {
            $lines[] = '';
            $lines[] = '## One more question';
            $lines[] = (string) $brief['one_more_question']['question'];
        }

        return implode("\n", $lines)."\n";
    }

    private function write(string $tenantId, array $brief): ?string
    {
        $path = 'reports/daily/'.$brief['date'].'.md';
        try {
            $version = $this->writer->write($tenantId, $path, $brief['markdown'], [
                'title' => 'Needs-You Today — '.$brief['date'],
                'source' => 'agent',
                'author_type' => 'system',
                'author_ref' => 'FounderBriefService',
                'change_summary' => 'Daily brief',
                'data_class' => 'internal',
            ]);

            return $version === null ? null : $path;
        } catch (\Throwable $e) {
            Log::warning('founder.brief.write_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    /** @return array<string, mixed> */
    private function item(
        string $kind,
        string $id,
        string $title,
        string $detail,
        array $action,
        float $score,
        ?Carbon $at,
        Carbon $now,
        ?float $money = null,
        array $extra = [],
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail,
            'action' => $action,
            'age' => $at ? self::age($at, $now) : null,
            'money' => $money,
            'score' => round($score, 3),
            'sort_at' => $at?->toIso8601String() ?? '',
            'at' => $at?->toIso8601String(),
        ] + $extra;
    }

    public static function age(Carbon $at, Carbon $now): string
    {
        // Carbon 3 diffs are signed floats: $at (past) → $now is positive.
        $minutes = (int) floor(max(0.0, (float) $at->diffInMinutes($now)));
        if ($minutes < 60) {
            return $minutes.'m';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 48) {
            return $hours.'h';
        }

        return intdiv($hours, 24).'d';
    }

    public static function money(float $amount): string
    {
        return '$'.number_format($amount, $amount >= 100 ? 0 : 2);
    }

    /** @param array<string, mixed> $context */
    private function moneyFrom(array $context): ?float
    {
        foreach (self::MONEY_KEYS as $key) {
            $value = $context[$key] ?? null;
            if (is_array($value)) {
                $value = $value['amount'] ?? $value['value'] ?? null;
            }
            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value;
            }
            if (is_string($value) && preg_match('/\d[\d,]*(?:\.\d+)?/', $value, $m)) {
                $n = (float) str_replace(',', '', $m[0]);
                if ($n > 0) {
                    return $n;
                }
            }
        }

        return null;
    }

    private static function firstQuestion(AgentRun $run): ?string
    {
        foreach ((array) $run->questions as $q) {
            if (is_string($q) && trim($q) !== '') {
                return trim($q);
            }
            if (is_array($q) && ! empty($q['question'])) {
                return trim((string) $q['question']);
            }
        }

        return null;
    }
}
