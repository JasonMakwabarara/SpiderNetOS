<?php

declare(strict_types=1);

namespace App\Services\Board;

use App\Models\BoardSession as SessionModel;
use App\Models\BoardTake;
use App\Models\BoardVerdict;
use App\Services\Brain\BrainStore;
use App\Services\Founder\BrainWriter;
use App\Services\Inference\InferencePlaneClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The converged board protocol (plan D6 §6).
 *
 *   Round 1  every seat answers the same brief, in isolation. No seat sees
 *            another seat's take, so five independent reads are five reads
 *            and not one read with four echoes.
 *   Round 2  the takes are relabelled A, B, C… with every name, display name
 *            and voice stripped, and handed back for cross-examination. A seat
 *            that changes its mind because of the argument is doing its job; a
 *            seat that changes its mind because of whose name was on it is not,
 *            which is why the names come off.
 *   Chair    the chairman writes the verdict table, the consensus, the
 *            minority report *verbatim*, one recommended action and a next
 *            check date.
 *
 * Seats never call seats (Hard Rule #2): every call goes out from here and
 * comes back here. Nothing in this class sends, posts or spends anything; the
 * recommendation becomes a `board_verdict` proposal for the founder.
 */
class BoardSession
{
    public const ROUND_INDEPENDENT = 1;

    public const ROUND_CROSS_EXAM = 2;

    /** A, B, C … — stable within a session, meaningless across sessions. */
    public const ANON_LABELS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    public function __construct(
        private readonly AdvisorRegistry $advisors,
        private readonly InferencePlaneClient $inference,
        private readonly BrainStore $brain,
        private readonly BrainWriter $writer,
    ) {}

    /**
     * Open a session and run it to a verdict.
     *
     * @param  list<string>|null  $onlySeats  seat slugs to sit; null = the tenant's whole board
     */
    public function convene(string $tenantId, string $question, ?string $openedBy = null, ?array $onlySeats = null): SessionModel
    {
        $seats = $this->advisors->seatsFor($tenantId);
        if ($onlySeats !== null) {
            $seats = array_values(array_filter($seats, fn (array $s): bool => in_array($s['slug'], $onlySeats, true)));
        }

        if ($seats === []) {
            throw new \RuntimeException('No advisor seats are available for this tenant.');
        }

        $brief = $this->brief($tenantId, $seats);

        $session = SessionModel::create([
            'tenant_id' => $tenantId,
            'opened_by' => $openedBy,
            'slug' => $this->slug($tenantId, $question),
            'question' => mb_substr(trim($question), 0, 2000),
            'brief' => $brief,
            'seats' => array_map(fn (array $s): array => [
                'slug' => $s['slug'], 'display_name' => $s['display_name'], 'seat' => $s['seat'],
                'likeness_mode' => $s['likeness_mode'], 'voice_persona' => $s['voice_persona'],
            ], $seats),
            'status' => 'round_1',
        ]);

        try {
            $this->round($session, $seats, self::ROUND_INDEPENDENT);
            $session->update(['status' => 'round_2', 'round' => 1]);

            $this->round($session, $seats, self::ROUND_CROSS_EXAM);
            $session->update(['status' => 'synthesis', 'round' => 2]);

            $this->synthesise($session, $seats);

            $session->update([
                'status' => 'complete',
                'completed_at' => now(),
                'brain_path' => $this->file($session),
            ]);
        } catch (\Throwable $e) {
            Log::error('board.session_failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);
            $session->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $session->fresh();
    }

    // ------------------------------------------------------------------ //
    //  Rounds
    // ------------------------------------------------------------------ //

    /** @param  list<array<string, mixed>>  $seats */
    private function round(SessionModel $session, array $seats, int $round): void
    {
        $priorTakes = $round === self::ROUND_CROSS_EXAM ? $this->anonymised($session, $seats) : [];

        foreach ($seats as $index => $seat) {
            $label = self::ANON_LABELS[$index] ?? (string) $index;

            $prompt = $round === self::ROUND_INDEPENDENT
                ? $this->independentPrompt($session, $seat)
                : $this->crossExamPrompt($session, $seat, $label, $priorTakes);

            $this->ask($session, $seat, $round, $label, $prompt);
        }
    }

    private function ask(SessionModel $session, array $seat, int $round, string $label, string $prompt): BoardTake
    {
        $take = BoardTake::updateOrCreate(
            ['session_id' => $session->id, 'seat' => $seat['slug'], 'round' => $round],
            ['tenant_id' => $session->tenant_id, 'anon_label' => $label],
        );

        try {
            $reply = $this->inference->generate(
                prompt: $prompt,
                systemPrompt: $this->advisors->promptFor($seat),
                costCeiling: (float) config('board.per_seat.cost_ceiling_usd', 0.12),
                maxTokens: (int) config('board.per_seat.max_tokens', 900),
                temperature: (float) config('board.per_seat.temperature', 0.4),
            );

            $parsed = VerdictSchema::parse($reply['text']);

            $take->forceFill([
                'verdict' => $parsed['verdict'],
                'raw' => mb_substr($reply['text'], 0, 8000),
                'cost_usd' => $reply['cost'],
                'tokens' => $reply['tokens_used'],
                'error' => $parsed['ok'] ? null : implode('; ', $parsed['errors']),
            ])->save();

            $session->increment('cost_usd', $reply['cost']);
            $session->increment('tokens', $reply['tokens_used']);
        } catch (\Throwable $e) {
            // One seat failing is a quieter board, not a failed session: four
            // considered views beat an error page.
            Log::warning('board.seat_failed', ['session_id' => $session->id, 'seat' => $seat['slug'], 'error' => $e->getMessage()]);
            $take->forceFill(['error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }

        return $take;
    }

    // ------------------------------------------------------------------ //
    //  Prompts
    // ------------------------------------------------------------------ //

    private function independentPrompt(SessionModel $session, array $seat): string
    {
        return implode("\n", [
            'QUESTION FOR THE BOARD',
            '',
            $session->question,
            '',
            $this->briefBlock($session, $seat),
            '',
            'Answer in your own frame. You have not seen any other seat\'s view and you should not',
            'guess at one.',
            '',
            VerdictSchema::instruction(),
        ]);
    }

    /** @param  list<array<string, mixed>>  $priorTakes */
    private function crossExamPrompt(SessionModel $session, array $seat, string $label, array $priorTakes): string
    {
        $others = array_values(array_filter($priorTakes, fn (array $t): bool => $t['label'] !== $label));

        $lines = [
            'QUESTION FOR THE BOARD',
            '',
            $session->question,
            '',
            $this->briefBlock($session, $seat),
            '',
            'THE OTHER SEATS, ANONYMISED',
            '',
            'These are the other views on the table. You are seat '.$label.'. The others are unlabelled',
            'on purpose: judge the argument, not who made it. Do not guess who anyone is, and do not',
            'address them by letter in your reasoning.',
            '',
        ];

        foreach ($others as $take) {
            $lines[] = 'Seat '.$take['label'].' — '.$take['stance'].' (confidence '.$take['confidence'].')';
            $lines[] = 'Number they watch: '.$take['one_number'];
            $lines[] = 'What would change their mind: '.$take['what_would_change_my_mind'];
            $lines[] = $take['reasoning'];
            $lines[] = '';
        }

        $lines[] = 'Now give your final verdict. Change your stance if the arguments warrant it and say';
        $lines[] = 'so plainly in your reasoning; hold it if they do not, and say why they did not move you.';
        $lines[] = '';
        $lines[] = VerdictSchema::instruction();

        return implode("\n", $lines);
    }

    private function briefBlock(SessionModel $session, array $seat): string
    {
        $scopes = $seat['brain_scopes'] !== [] ? $seat['brain_scopes'] : (array) config('board.default_scopes', []);
        $brief = (array) ($session->brief['files'] ?? []);

        $lines = ['BRIEF — the only facts you may use'];
        $any = false;

        foreach ($brief as $path => $file) {
            if (! in_array($path, $scopes, true)) {
                continue;
            }
            $lines[] = '';
            $lines[] = '--- '.$path.' (v'.($file['version'] ?? 1).')';
            $lines[] = (string) ($file['content'] ?? '');
            $any = true;
        }

        if (! $any) {
            $lines[] = '';
            $lines[] = '(The brain holds nothing in your scope yet. Say so, and put what you would need';
            $lines[] = 'into what_would_change_my_mind rather than inventing it.)';
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------ //
    //  Anonymisation
    // ------------------------------------------------------------------ //

    /**
     * Round 1 takes with every identifying trace removed.
     *
     * Names are not merely omitted from the template: the seat's display name,
     * slug and any `inspired_by` string are redacted out of the free text too,
     * because a seat that signs its own reasoning would defeat the whole round.
     *
     * @return list<array<string, mixed>>
     */
    private function anonymised(SessionModel $session, array $seats): array
    {
        $takes = BoardTake::where('session_id', $session->id)->where('round', self::ROUND_INDEPENDENT)->get();
        $labelFor = [];
        $names = [];

        foreach ($seats as $index => $seat) {
            $labelFor[$seat['slug']] = self::ANON_LABELS[$index] ?? (string) $index;
            $names = array_merge($names, $this->advisors->redactTermsFor($seat));
        }

        $anon = [];
        foreach ($takes as $take) {
            $verdict = (array) $take->verdict;
            if (($verdict['reasoning'] ?? '') === '') {
                continue;
            }

            $anon[] = [
                'label' => $labelFor[$take->seat] ?? '?',
                'stance' => (string) ($verdict['stance'] ?? 'depends'),
                'confidence' => (string) ($verdict['confidence'] ?? '0.5'),
                'one_number' => self::redact((string) ($verdict['one_number'] ?? ''), $names),
                'what_would_change_my_mind' => self::redact((string) ($verdict['what_would_change_my_mind'] ?? ''), $names),
                'reasoning' => self::redact((string) ($verdict['reasoning'] ?? ''), $names),
            ];
        }

        return $anon;
    }

    /**
     * Strip the given terms out of free text.
     *
     * The boundary is "not a letter" rather than a word boundary, so
     * "Hormozi-style" and "Hormozi's" are caught as readily as "Hormozi".
     * Terms are whole phrases and proper names, never individual framework
     * words: redacting "offer" would maul "grand slam offer" without hiding
     * anything a reader could have used to identify the seat.
     *
     * @param  list<string>  $terms
     */
    public static function redact(string $text, array $terms): string
    {
        $terms = array_unique(array_filter($terms, fn (string $t): bool => mb_strlen(trim($t)) > 3));

        // Longest first, so "Alex Hormozi" is replaced as a phrase before
        // "Hormozi" would turn it into "Alex a seat".
        usort($terms, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($terms as $term) {
            $text = preg_replace(
                '/(?<!\p{L})'.preg_quote(trim($term), '/').'(?!\p{L})/iu',
                'a seat',
                $text,
            ) ?? $text;
        }

        return $text;
    }

    // ------------------------------------------------------------------ //
    //  Synthesis
    // ------------------------------------------------------------------ //

    private function synthesise(SessionModel $session, array $seats): BoardVerdict
    {
        $takes = BoardTake::where('session_id', $session->id)->where('round', self::ROUND_CROSS_EXAM)->get();
        $byName = [];
        foreach ($seats as $seat) {
            $byName[$seat['slug']] = $seat['display_name'];
        }

        $rows = [];
        $killCriteria = [];
        foreach ($takes as $take) {
            $verdict = (array) $take->verdict;
            if ($verdict === []) {
                continue;
            }
            $rows[] = [
                'seat' => $take->seat,
                'display_name' => $byName[$take->seat] ?? $take->seat,
                'stance' => (string) ($verdict['stance'] ?? 'depends'),
                'confidence' => (float) ($verdict['confidence'] ?? 0.5),
                'one_number' => (string) ($verdict['one_number'] ?? ''),
                'reasoning' => (string) ($verdict['reasoning'] ?? ''),
            ];
            foreach ((array) ($verdict['kill_criteria'] ?? []) as $criterion) {
                $killCriteria[] = (string) $criterion;
            }
        }

        $minority = $this->minority($rows);
        $chair = $this->advisors->chair((string) $session->tenant_id);
        $synthesis = $chair === null ? null : $this->askChair($session, $chair, $rows, $minority);

        return BoardVerdict::updateOrCreate(
            ['session_id' => $session->id],
            [
                'tenant_id' => $session->tenant_id,
                'consensus' => $synthesis['consensus'] ?? $this->fallbackConsensus($rows),
                'table_rows' => array_map(fn (array $r): array => Arr::except($r, ['reasoning']), $rows),
                // Verbatim, always: the chairman may introduce the dissent but
                // never rewrite it.
                'minority_report' => $minority['reasoning'] ?? null,
                'minority_seat' => $minority['seat'] ?? null,
                'recommended_action' => $synthesis['recommended_action'] ?? $this->fallbackAction($rows),
                'next_check_date' => $synthesis['next_check_date'] ?? now()->addDays(14)->toDateString(),
                'kill_criteria' => array_values(array_unique($killCriteria)),
                'meta' => ['chair' => $chair['slug'] ?? null, 'seats' => count($rows), 'disclaimer' => config('board.disclaimer')],
            ],
        );
    }

    /**
     * The seat furthest from the room, by stance then by confidence. Null when
     * the board actually agrees — a manufactured dissent is worse than none.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function minority(array $rows): ?array
    {
        if (count($rows) < 2) {
            return null;
        }

        $counts = array_count_values(array_column($rows, 'stance'));
        arsort($counts);
        $majority = (string) array_key_first($counts);

        if ($counts[$majority] === count($rows)) {
            return null;
        }

        $dissenters = array_values(array_filter($rows, fn (array $r): bool => $r['stance'] !== $majority));
        usort($dissenters, fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $dissenters[0];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function askChair(SessionModel $session, array $chair, array $rows, ?array $minority): ?array
    {
        if ($rows === []) {
            return null;
        }

        $lines = [
            'QUESTION PUT TO THE BOARD',
            '',
            $session->question,
            '',
            'THE SEATS, AFTER CROSS-EXAMINATION',
            '',
        ];

        foreach ($rows as $row) {
            $lines[] = $row['display_name'].' — '.$row['stance'].' (confidence '.$row['confidence'].')';
            $lines[] = 'Number they watch: '.$row['one_number'];
            $lines[] = $row['reasoning'];
            $lines[] = '';
        }

        if ($minority !== null) {
            $lines[] = 'The dissenting seat is '.$minority['display_name'].'. Their words go into the report';
            $lines[] = 'unchanged — introduce the dissent, do not rewrite or soften it.';
            $lines[] = '';
        }

        $lines[] = 'Write the chairman\'s synthesis. Return one JSON object and nothing else:';
        $lines[] = '{';
        $lines[] = '  "consensus": "where the board agrees and where it does not, in under 1200 characters",';
        $lines[] = '  "recommended_action": "one concrete action the founder can take this week",';
        $lines[] = '  "next_check_date": "YYYY-MM-DD, when this should be looked at again"';
        $lines[] = '}';
        $lines[] = 'Use only what the seats said. Add no new facts and no new numbers.';

        try {
            $reply = $this->inference->generate(
                prompt: implode("\n", $lines),
                systemPrompt: $this->advisors->promptFor($chair),
                costCeiling: (float) config('board.chair.cost_ceiling_usd', 0.25),
                maxTokens: (int) config('board.chair.max_tokens', 1600),
                temperature: (float) config('board.chair.temperature', 0.2),
            );

            $session->increment('cost_usd', $reply['cost']);
            $session->increment('tokens', $reply['tokens_used']);

            $decoded = json_decode(trim($reply['text']), true);
            if (! is_array($decoded) && preg_match('/\{.*\}/s', $reply['text'], $m)) {
                $decoded = json_decode($m[0], true);
            }
            if (! is_array($decoded)) {
                return null;
            }

            $date = is_string($decoded['next_check_date'] ?? null) ? $decoded['next_check_date'] : null;

            return [
                'consensus' => mb_substr(trim((string) ($decoded['consensus'] ?? '')), 0, 1400) ?: null,
                'recommended_action' => mb_substr(trim((string) ($decoded['recommended_action'] ?? '')), 0, 600) ?: null,
                'next_check_date' => $this->date($date),
            ];
        } catch (\Throwable $e) {
            Log::warning('board.chair_failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function date(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function fallbackConsensus(array $rows): string
    {
        if ($rows === []) {
            return 'No seat returned a usable verdict. The board did not sit; nothing here is advice.';
        }

        $counts = array_count_values(array_column($rows, 'stance'));
        arsort($counts);
        $parts = [];
        foreach ($counts as $stance => $n) {
            $parts[] = $n.' × '.str_replace('_', ' ', (string) $stance);
        }

        return 'The chairman did not answer, so this is the tally rather than a synthesis: '.implode(', ', $parts).'.';
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function fallbackAction(array $rows): string
    {
        foreach ($rows as $row) {
            if ($row['one_number'] !== '') {
                return 'Watch this for two weeks before deciding: '.$row['one_number'];
            }
        }

        return 'Re-run the board once the brain holds the files the seats asked for.';
    }

    // ------------------------------------------------------------------ //
    //  Filing
    // ------------------------------------------------------------------ //

    private function file(SessionModel $session): ?string
    {
        $verdict = BoardVerdict::where('session_id', $session->id)->first();
        if ($verdict === null) {
            return null;
        }

        $path = 'reports/board/'.now()->toDateString().'-'.$session->slug.'.md';

        try {
            $version = $this->writer->write((string) $session->tenant_id, $path, self::markdown($session, $verdict), [
                'title' => 'Board session — '.mb_substr($session->question, 0, 80),
                'source' => 'agent',
                'author_type' => 'system',
                'author_ref' => 'BoardSession',
                'change_summary' => 'Board verdict',
                'data_class' => 'confidential',
            ]);

            return $version === null ? null : $path;
        } catch (\Throwable $e) {
            Log::warning('board.file_failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public static function markdown(SessionModel $session, BoardVerdict $verdict): string
    {
        $lines = [
            '# Board session — '.$session->question,
            '',
            '_'.now()->toDateString().' · '.count((array) $verdict->table_rows).' seats · '
                .config('board.disclaimer').'_',
            '',
            '## The table',
            '',
            '| Seat | Stance | Confidence | The number they watch |',
            '| --- | --- | ---: | --- |',
        ];

        foreach ((array) $verdict->table_rows as $row) {
            $lines[] = sprintf(
                '| %s | %s | %.2f | %s |',
                $row['display_name'] ?? $row['seat'],
                str_replace('_', ' ', (string) ($row['stance'] ?? '')),
                (float) ($row['confidence'] ?? 0),
                $row['one_number'] ?? '',
            );
        }

        $lines[] = '';
        $lines[] = '## Consensus';
        $lines[] = '';
        $lines[] = (string) $verdict->consensus;

        if ($verdict->minority_report !== null) {
            $lines[] = '';
            $lines[] = '## Minority report';
            $lines[] = '';
            $lines[] = '_'.($verdict->minority_seat ?? 'One seat').', verbatim:_';
            $lines[] = '';
            $lines[] = '> '.str_replace("\n", "\n> ", (string) $verdict->minority_report);
        }

        $lines[] = '';
        $lines[] = '## Recommended action';
        $lines[] = '';
        $lines[] = (string) $verdict->recommended_action;
        if ($verdict->next_check_date !== null) {
            $lines[] = '';
            $lines[] = '**Next check:** '.Carbon::parse($verdict->next_check_date)->toDateString();
        }

        if ((array) $verdict->kill_criteria !== []) {
            $lines[] = '';
            $lines[] = '## Kill criteria';
            $lines[] = '';
            foreach ((array) $verdict->kill_criteria as $criterion) {
                $lines[] = '- '.$criterion;
            }
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'The seats are archetypes that reason with published frameworks. They are not the people';
        $lines[] = 'those frameworks belong to, they do not speak for them, and this is '
            .lcfirst((string) config('board.disclaimer'));

        return implode("\n", $lines)."\n";
    }

    // ------------------------------------------------------------------ //
    //  Brief
    // ------------------------------------------------------------------ //

    /** @param  list<array<string, mixed>>  $seats */
    private function brief(string $tenantId, array $seats): array
    {
        $scopes = [];
        foreach ($seats as $seat) {
            foreach ($seat['brain_scopes'] as $path) {
                $scopes[$path] = true;
            }
        }
        if ($scopes === []) {
            foreach ((array) config('board.default_scopes', []) as $path) {
                $scopes[$path] = true;
            }
        }

        $files = [];
        $missing = [];
        foreach (array_keys($scopes) as $path) {
            try {
                $file = $this->brain->read($tenantId, $path);
            } catch (\Throwable) {
                $file = null;
            }

            if ($file === null || trim((string) $file->content) === '') {
                $missing[] = $path;

                continue;
            }

            $files[$path] = ['version' => (int) $file->version, 'content' => mb_substr((string) $file->content, 0, 6000)];
        }

        return ['files' => $files, 'missing' => $missing, 'built_at' => now()->toIso8601String()];
    }

    private function slug(string $tenantId, string $question): string
    {
        $base = Str::slug(Str::words($question, 8, '')) ?: 'session';
        $slug = mb_substr($base, 0, 100);

        $n = 1;
        while (SessionModel::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = mb_substr($base, 0, 96).'-'.(++$n);
        }

        return $slug;
    }
}
