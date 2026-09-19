<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\NewsletterIssue;
use App\Services\Content\QuoteBank;
use App\Services\Skills\SkillRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The C-Suite newsletter (plan D8 #15, Jason 2026-09-16).
 *
 * Internal, one recipient, never a third-party list: it rides with the Monday
 * letter as its second half. The Monday letter is the numbers — this is the
 * other side of the same week:
 *
 *   what went right · wins by character · one thing learned ·
 *   one thing to look forward to · the people · a quote
 *
 * The brief is **positivity**, which is not the same as flattery. Everything
 * here is a fact that happened, counted from the ledger; a quiet week is
 * reported as a quiet week. Manufacturing a win would make the whole letter
 * worthless on the week it actually mattered.
 *
 * Nothing here calls a model. The quote is chosen from a curated bank, never
 * generated, so no attribution is ever invented.
 */
class CSuiteNewsletterComposer
{
    /** At most this many character wins, so the letter stays readable. */
    public const MAX_WINS = 6;

    public function __construct(
        private readonly QuoteBank $quotes,
        private readonly TrustLedger $trust,
        private readonly ?SkillRegistry $skills = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context  the Monday letter's numbers + trust, so the two halves agree
     * @return array{period: string, mood: string, wins: list<array<string, mixed>>, learned: string|null, ahead: list<string>, people: list<string>, quote: array<string, mixed>|null, markdown: string}
     */
    public function compose(string $tenantId, Carbon $weekStart, array $context = []): array
    {
        $weekEnd = $weekStart->copy()->addWeek();
        $period = $weekStart->format('o-\WW');

        $wins = $this->wins($tenantId, $weekStart, $weekEnd, $context);
        $mood = $this->mood($wins, $context);
        $quote = $this->quotes->pick($tenantId, $mood, $tenantId.'|'.$period, $period);

        $issue = [
            'period' => $period,
            'mood' => $mood,
            'wins' => $wins,
            'learned' => $this->learned($tenantId, $weekStart, $weekEnd),
            'ahead' => $this->ahead($tenantId, $weekEnd),
            'people' => $this->people($tenantId, $weekStart, $weekEnd),
            'quote' => $quote,
        ];

        $issue['markdown'] = $this->markdown($issue);

        return $issue;
    }

    // ------------------------------------------------------------------ //
    //  What went right, by character
    // ------------------------------------------------------------------ //

    /**
     * "Nexus booked 4 meetings; Prism found 2 comparables" — counted per core
     * character, because that is the unit the user actually thinks in.
     *
     * @return list<array<string, mixed>>
     */
    private function wins(string $tenantId, Carbon $from, Carbon $to, array $context): array
    {
        if (! Schema::hasTable('agent_runs')) {
            return [];
        }

        $rows = ($context['trust']['rows'] ?? null) ?: $this->trust->forWeek($tenantId, $from, $to)['rows'];

        $byCharacter = [];
        foreach ($rows as $row) {
            $succeeded = max(0, $row['runs'] - $row['failed']);
            if ($succeeded === 0 && $row['drafts'] === 0) {
                continue;
            }

            $character = $this->characterFor((string) $row['skill_slug']);
            $byCharacter[$character] ??= ['character' => $character, 'runs' => 0, 'drafts' => 0, 'accepted' => 0, 'skills' => []];
            $byCharacter[$character]['runs'] += $succeeded;
            $byCharacter[$character]['drafts'] += $row['drafts'];
            $byCharacter[$character]['accepted'] += $row['clean'] + $row['edited'];
            $byCharacter[$character]['skills'][] = $row['skill_slug'];
        }

        $wins = array_values($byCharacter);
        usort($wins, fn (array $a, array $b): int => [$b['drafts'], $b['runs'], $a['character']] <=> [$a['drafts'], $a['runs'], $b['character']]);
        $wins = array_slice($wins, 0, self::MAX_WINS);

        foreach ($wins as $i => $win) {
            $wins[$i]['sentence'] = $this->winSentence($win);
        }

        return $wins;
    }

    private function winSentence(array $win): string
    {
        $name = ucfirst($win['character']);
        $parts = [];

        if ($win['drafts'] > 0) {
            $parts[] = sprintf('%d draft%s', $win['drafts'], $win['drafts'] === 1 ? '' : 's');
        }
        if ($win['accepted'] > 0) {
            $parts[] = sprintf('%d of them you kept', $win['accepted']);
        }
        if ($parts === []) {
            $parts[] = sprintf('%d run%s', $win['runs'], $win['runs'] === 1 ? '' : 's');
        }

        $skills = array_slice(array_unique($win['skills']), 0, 2);

        return sprintf('**%s** — %s (%s)', $name, implode(', ', $parts), implode(', ', $skills));
    }

    private function characterFor(string $skillSlug): string
    {
        if ($this->skills === null) {
            return 'atlas';
        }

        try {
            $card = $this->skills->get($skillSlug);

            return $card === null ? 'atlas' : $this->skills->coreAgentFor($card);
        } catch (\Throwable) {
            return 'atlas';
        }
    }

    // ------------------------------------------------------------------ //
    //  One thing learned — from the edit ledger, not from a model
    // ------------------------------------------------------------------ //

    private function learned(string $tenantId, Carbon $from, Carbon $to): ?string
    {
        if (! Schema::hasTable('artifact_revisions')) {
            return null;
        }

        $revisions = DB::table('artifact_revisions')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->get(['categories', 'skill_slug', 'why']);

        if ($revisions->isEmpty()) {
            return null;
        }

        $counts = [];
        foreach ($revisions as $revision) {
            $categories = is_string($revision->categories) ? (json_decode($revision->categories, true) ?: []) : (array) $revision->categories;
            foreach ($categories as $category) {
                $counts[(string) $category] = ($counts[(string) $category] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return sprintf('You edited %d draft%s this week; none of the edits fell into a single pattern yet.',
                $revisions->count(), $revisions->count() === 1 ? '' : 's');
        }

        arsort($counts);
        $top = (string) array_key_first($counts);

        return sprintf(
            'Of the %d edit%s you made this week, %d touched **%s**. That is the correction the agents should stop needing.',
            $revisions->count(),
            $revisions->count() === 1 ? '' : 's',
            $counts[$top],
            $top,
        );
    }

    // ------------------------------------------------------------------ //
    //  One thing to look forward to
    // ------------------------------------------------------------------ //

    /** @return list<string> */
    private function ahead(string $tenantId, Carbon $from): array
    {
        $ahead = [];
        $horizon = $from->copy()->addDays(14);

        if (Schema::hasTable('deals')) {
            $closing = DB::table('deals')
                ->where('tenant_id', $tenantId)
                ->whereNull('closed_at')
                ->whereNotNull('expected_close_at')
                ->whereBetween('expected_close_at', [$from, $horizon])
                ->get(['value_cents']);
            if ($closing->isNotEmpty()) {
                $value = 0.0;
                foreach ($closing as $deal) {
                    $value += ((int) $deal->value_cents) / 100;
                }
                $ahead[] = sprintf('%d deal%s worth %s are due to close in the next two weeks.',
                    $closing->count(), $closing->count() === 1 ? '' : 's', WeeklyNumbers::money($value));
            }
        }

        if (Schema::hasTable('invoices')) {
            $incoming = (float) DB::table('invoices')
                ->where('tenant_id', $tenantId)
                ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
                ->whereBetween('due_date', [$from->toDateString(), $horizon->toDateString()])
                ->sum('total_amount');
            if ($incoming > 0) {
                $ahead[] = sprintf('%s of invoiced work falls due in the next two weeks.', WeeklyNumbers::money($incoming));
            }
        }

        if ($ahead === []) {
            $ahead[] = 'Nothing is scheduled to land in the next two weeks — a good fortnight to put something in the pipe.';
        }

        return $ahead;
    }

    // ------------------------------------------------------------------ //
    //  The people — the Knowledge brain learns about them too
    // ------------------------------------------------------------------ //

    /** @return list<string> */
    private function people(string $tenantId, Carbon $from, Carbon $to): array
    {
        $notes = [];

        if (Schema::hasTable('users')) {
            $active = DB::table('users')
                ->where('tenant_id', $tenantId)
                ->where('last_login_at', '>=', $from)
                ->count();
            if ($active > 0) {
                $notes[] = $active === 1
                    ? 'One person was in the cockpit this week.'
                    : sprintf('%d people were in the cockpit this week.', $active);
            }
        }

        if (Schema::hasTable('approval_reviews')) {
            $reviewed = DB::table('approval_reviews')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->count();
            if ($reviewed > 0) {
                $notes[] = sprintf('%d approval%s went through a real review, not a rubber stamp.',
                    $reviewed, $reviewed === 1 ? '' : 's');
            }
        }

        return $notes;
    }

    // ------------------------------------------------------------------ //
    //  Mood — which decides the quote
    // ------------------------------------------------------------------ //

    private function mood(array $wins, array $context): string
    {
        $numbers = $context['numbers'] ?? [];
        $drafts = array_sum(array_column($wins, 'drafts'));
        $runs = array_sum(array_column($wins, 'runs'));

        $trouble = false;
        foreach (['overdue_ar', 'spend_vs_budget'] as $key) {
            $number = $numbers[$key] ?? null;
            if (is_array($number) && ($number['available'] ?? false)) {
                if ($key === 'overdue_ar' && (float) ($number['value'] ?? 0) > 0) {
                    $trouble = true;
                }
                if ($key === 'spend_vs_budget' && ($number['extra']['over'] ?? []) !== []) {
                    $trouble = true;
                }
            }
        }

        if ($runs === 0 && $drafts === 0) {
            return 'beginning';
        }
        if ($trouble) {
            return 'steady';
        }

        return $drafts >= 10 ? 'celebratory' : 'focused';
    }

    // ------------------------------------------------------------------ //
    //  Render
    // ------------------------------------------------------------------ //

    /** @param  array<string, mixed>  $issue */
    public function markdown(array $issue): string
    {
        $lines = ['## The C-Suite letter', ''];

        if ($issue['wins'] === []) {
            $lines[] = 'A quiet week: no agent finished a piece of work. That is worth knowing too — nothing is';
            $lines[] = 'running that you have not turned on.';
        } else {
            $lines[] = '**What went right**';
            $lines[] = '';
            foreach ($issue['wins'] as $win) {
                $lines[] = '- '.$win['sentence'];
            }
        }

        if ($issue['learned'] !== null) {
            $lines[] = '';
            $lines[] = '**One thing learned**';
            $lines[] = '';
            $lines[] = $issue['learned'];
        }

        if ($issue['ahead'] !== []) {
            $lines[] = '';
            $lines[] = '**To look forward to**';
            $lines[] = '';
            foreach ($issue['ahead'] as $item) {
                $lines[] = '- '.$item;
            }
        }

        if ($issue['people'] !== []) {
            $lines[] = '';
            $lines[] = '**The people**';
            $lines[] = '';
            foreach ($issue['people'] as $note) {
                $lines[] = '- '.$note;
            }
        }

        if ($issue['quote'] !== null) {
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
            $lines[] = QuoteBank::markdown($issue['quote']);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * File the issue so the quote is not repeated and the letter can be re-read.
     * Idempotent per tenant + week.
     */
    public function record(string $tenantId, array $issue, ?string $brainPath = null): NewsletterIssue
    {
        return NewsletterIssue::updateOrCreate(
            ['tenant_id' => $tenantId, 'kind' => NewsletterIssue::KIND_CSUITE, 'period' => $issue['period']],
            [
                'status' => NewsletterIssue::STATUS_DRAFT,
                'subject' => 'The C-Suite letter — week '.$issue['period'],
                'markdown' => $issue['markdown'],
                'quote_id' => $issue['quote']['id'] ?? null,
                'brain_path' => $brainPath,
                'channel' => NewsletterIssue::CHANNEL_COCKPIT,
                'meta' => ['mood' => $issue['mood'], 'wins' => count($issue['wins'])],
            ],
        );
    }
}
