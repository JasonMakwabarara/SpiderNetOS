<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\NewsletterIssue;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * The curated quote bank behind the C-Suite newsletter (plan D8 #15):
 * zen / buddhist / stoic / hopeful / lighthearted / proverb, attributed, never
 * invented.
 *
 * Picking is deterministic, not random: given the same week and the same
 * history the same quote comes back, so a re-composed issue does not quietly
 * change under the reader. The pick is (1) restricted to quotes matching the
 * week's mood, (2) filtered to ones this tenant has not had in its last
 * NO_REPEAT_WITHIN issues, (3) ordered by a hash of tenant + period.
 *
 * Nothing here calls a model. A quote is only ever selected, never generated,
 * because an invented attribution is a lie with the founder's name on it.
 */
class QuoteBank
{
    /** No quote may repeat for a tenant inside this many issues. */
    public const NO_REPEAT_WITHIN = 26;

    public const TRADITIONS = ['zen', 'buddhist', 'stoic', 'hopeful', 'lighthearted', 'proverb'];

    /**
     * Mood → tone tags, best first. The composer reads the week's numbers and
     * names a mood; the bank finds a line that fits it.
     */
    public const MOODS = [
        // A good week: wins outnumber the misses.
        'celebratory' => ['lighthearted', 'wit', 'hopeful', 'team', 'gratitude'],
        // A hard week: revenue down, deals stalled, something broke.
        'steady' => ['resilience', 'patience', 'calm', 'perspective', 'grounded'],
        // A busy week with plenty shipped but plenty still open.
        'focused' => ['work', 'focus', 'presence', 'simple', 'agency'],
        // A quiet week: little happened, mostly setup.
        'beginning' => ['curiosity', 'learning', 'humility', 'asking', 'agency'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $parsed = null;

    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? (string) config('agents.quote_bank', dirname(base_path()).'/packages/content/quotes.yaml');
    }

    /**
     * Every quote in the bank, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->parsed !== null) {
            return $this->parsed;
        }

        $path = $this->path();
        if (! is_readable($path)) {
            Log::warning('quotes.bank_missing', ['path' => $path]);

            return $this->parsed = [];
        }

        try {
            $raw = Yaml::parseFile($path);
        } catch (\Throwable $e) {
            Log::warning('quotes.bank_unreadable', ['path' => $path, 'error' => $e->getMessage()]);

            return $this->parsed = [];
        }

        $quotes = [];
        foreach ((array) ($raw['quotes'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['id'], $entry['text'])) {
                continue;
            }
            $id = (string) $entry['id'];
            $quotes[$id] = [
                'id' => $id,
                'text' => trim((string) $entry['text']),
                'attribution' => trim((string) ($entry['attribution'] ?? 'traditional')),
                'tradition' => (string) ($entry['tradition'] ?? 'proverb'),
                'tone_tags' => array_values(array_map('strval', (array) ($entry['tone_tags'] ?? []))),
                'public_domain' => (bool) ($entry['public_domain'] ?? false),
                'source_note' => isset($entry['source_note']) ? (string) $entry['source_note'] : null,
            ];
        }

        return $this->parsed = $quotes;
    }

    /**
     * The ids this tenant has already used, most recent first.
     *
     * @param  string|null  $exceptPeriod  the issue being (re)composed, so its own
     *                                     quote does not exclude itself
     */
    public function recentlyUsed(string $tenantId, int $limit = self::NO_REPEAT_WITHIN, ?string $exceptPeriod = null): array
    {
        return NewsletterIssue::forTenant($tenantId)
            ->ofKind(NewsletterIssue::KIND_CSUITE)
            ->whereNotNull('quote_id')
            ->when($exceptPeriod !== null, fn ($q) => $q->where('period', '!=', $exceptPeriod))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('quote_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** The quote already filed for this issue, if it has one. */
    private function alreadyChosen(string $tenantId, ?string $period): ?array
    {
        if ($period === null) {
            return null;
        }

        $id = NewsletterIssue::forTenant($tenantId)
            ->ofKind(NewsletterIssue::KIND_CSUITE)
            ->where('period', $period)
            ->value('quote_id');

        return $id === null ? null : ($this->all()[(string) $id] ?? null);
    }

    /**
     * Pick the quote for one issue.
     *
     * @param  string  $mood  one of MOODS; anything else falls back to 'focused'
     * @param  string  $seed  stable per issue — the tenant id + the period key
     * @param  string|null  $period  the issue's period; once an issue has a quote,
     *                               re-composing that week returns the same one
     * @return array<string, mixed>|null null only when the bank is empty
     */
    public function pick(string $tenantId, string $mood, string $seed, ?string $period = null): ?array
    {
        $quotes = $this->all();
        if ($quotes === []) {
            return null;
        }

        // A letter that quietly changes its quote when it is re-read is not the
        // same letter. Once filed, the choice is final for that week.
        $already = $this->alreadyChosen($tenantId, $period);
        if ($already !== null) {
            $already['why'] = $this->why($mood);

            return $already;
        }

        $used = array_flip($this->recentlyUsed($tenantId, self::NO_REPEAT_WITHIN, $period));
        $fresh = array_values(array_filter($quotes, fn (array $q): bool => ! isset($used[$q['id']])));

        // 26 issues in and every quote used? Better a repeat than no quote.
        $pool = $fresh !== [] ? $fresh : array_values($quotes);

        $tags = self::MOODS[$mood] ?? self::MOODS['focused'];
        $matching = array_values(array_filter(
            $pool,
            fn (array $q): bool => array_intersect($tags, $q['tone_tags']) !== [] || in_array($q['tradition'], $tags, true),
        ));
        $pool = $matching !== [] ? $matching : $pool;

        // Deterministic, uniform, and stable across re-composition.
        usort($pool, fn (array $a, array $b): int => $this->rank($seed, $a['id']) <=> $this->rank($seed, $b['id']));

        $chosen = $pool[0];
        $chosen['why'] = $this->why($mood);

        return $chosen;
    }

    /** One line on why the quote fits the week — stated, not generated. */
    private function why(string $mood): string
    {
        return match ($mood) {
            'celebratory' => 'A good week deserves a light line.',
            'steady' => 'A week that asked for patience more than speed.',
            'beginning' => 'Early days: the work is still mostly questions.',
            default => 'A full week of doing the work in front of you.',
        };
    }

    private function rank(string $seed, string $id): string
    {
        return hash('sha256', $seed.'|'.$id);
    }

    /** Render the quote block for the newsletter. */
    public static function markdown(array $quote): string
    {
        $lines = ['> '.$quote['text'], '>', '> — '.$quote['attribution']];
        if (! empty($quote['why'])) {
            $lines[] = '';
            $lines[] = '_'.$quote['why'].'_';
        }

        return implode("\n", $lines);
    }
}
