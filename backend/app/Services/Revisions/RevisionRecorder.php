<?php

declare(strict_types=1);

namespace App\Services\Revisions;

use App\Models\ArtifactRevision;
use Illuminate\Support\Facades\Schema;

/**
 * "Every edit is a lesson" (plan D8 #1).
 *
 * Records the original body next to what a human actually approved/sent, with
 * a normalised edit distance in [0, 1] and deterministic categories
 * (facts / links / numbers / length / tone / ask) from simple detectors, so
 * the promotion gate can count clean drafts (distance < 0.05) and the weekly
 * DistilCorrectionsJob can turn recurring corrections into brain rules.
 *
 * Distance: Levenshtein over whitespace-normalised text for short bodies;
 * word-level edit distance for medium bodies; sentence-level edit distance
 * (with fractional substitution cost) for long ones, so cost stays bounded.
 */
class RevisionRecorder
{
    public const CLEAN_THRESHOLD = 0.05;

    /** Char-level Levenshtein up to this many characters (both sides). */
    public const CHAR_LIMIT = 1200;

    /** Word-level edit distance up to this many words (both sides). */
    public const WORD_LIMIT = 600;

    private const URL_PATTERN = '~(?:https?://|www\.)[^\s<>"\'\)\]]+~iu';

    private const NUMBER_PATTERN = '/(?<![\w.\-])[£$€]?\d[\d,]*(?:\.\d+)?(?:%|k|K|m|M)?(?![\w.])/u';

    private const CTA_PATTERN = '/\b(let me know|would you|could you|can we|shall we|can you|book|schedule|a call|reply|get back|are you open|interested|free to|worth a|quick chat|hop on|jump on|set up|meet|sign up|try it|get started|call me|when works|does .{1,30} work)\b/iu';

    private const HYPE_WORDS = [
        'amazing', 'incredible', 'revolutionary', 'guaranteed', 'massive', 'game-changing', 'awesome', 'super',
        'excited', 'thrilled', 'love', 'huge', 'best', 'world-class', 'cutting-edge', 'unbelievable', 'fantastic',
        'honestly', 'really', 'very', 'just', 'literally', 'absolutely', 'perfect', 'exciting', 'unique',
    ];

    private const GREETINGS = ['hey', 'hi', 'hello', 'dear', 'good morning', 'good afternoon', 'greetings', 'yo'];

    private const POLITENESS = ['please', 'thanks', 'thank you', 'appreciate', 'kindly', 'regards', 'cheers', 'best wishes', 'sincerely'];

    private const NOT_ENTITIES = ['i', 'i\'m', 'i\'ll', 'i\'ve', 'i\'d', 'a', 'the', 'and', 'or', 'but', 'so', 'if', 'to', 'of', 'in', 'on', 'at', 'for', 'we', 'you', 'it', 'is', 'as', 'by'];

    /**
     * @param  array<string, mixed>  $meta  Optional: why (chip), action (edit|reject|reclassify), skill_slug, plus anything else worth keeping.
     */
    public function record(
        string $tenantId,
        string $subjectType,
        string $subjectId,
        string $original,
        string $edited,
        ?string $userId = null,
        array $meta = [],
    ): ArtifactRevision {
        $distance = $this->distance($original, $edited);
        $categories = $this->categories($original, $edited);

        $action = (string) ($meta['action'] ?? ArtifactRevision::ACTION_EDIT);
        if (! in_array($action, ArtifactRevision::ACTIONS, true)) {
            $action = ArtifactRevision::ACTION_EDIT;
        }

        $why = isset($meta['why']) && is_string($meta['why']) && trim($meta['why']) !== ''
            ? mb_substr(trim($meta['why']), 0, 160)
            : null;

        $skillSlug = isset($meta['skill_slug']) && is_string($meta['skill_slug']) && $meta['skill_slug'] !== ''
            ? mb_substr($meta['skill_slug'], 0, 64)
            : null;

        return ArtifactRevision::create([
            'tenant_id' => $tenantId,
            'subject_type' => in_array($subjectType, ArtifactRevision::SUBJECT_TYPES, true) ? $subjectType : ArtifactRevision::SUBJECT_AGENT_ARTIFACT,
            'subject_id' => $subjectId,
            'user_id' => $userId,
            'action' => $action,
            'skill_slug' => $skillSlug,
            'original_body' => $original,
            'edited_body' => $edited,
            'distance' => round($distance, 4),
            'categories' => $categories,
            'why' => $why,
            'meta' => array_diff_key($meta, ['why' => null, 'action' => null, 'skill_slug' => null]),
        ]);
    }

    /** A draft counts as clean (untouched in substance) below 5 % edit distance. */
    public function isClean(float $distance): bool
    {
        return $distance < self::CLEAN_THRESHOLD;
    }

    /** Share of clean revisions for a subject type + skill over a window — the promotion gate's input. */
    public function cleanShare(string $tenantId, ?string $skillSlug = null, int $days = 30): ?float
    {
        if (! Schema::hasTable('artifact_revisions')) {
            return null;
        }

        $query = ArtifactRevision::forTenant($tenantId)->where('created_at', '>=', now()->subDays($days));
        if ($skillSlug !== null) {
            $query->where('skill_slug', $skillSlug);
        }
        $total = (clone $query)->count();
        if ($total === 0) {
            return null;
        }
        $clean = (clone $query)->where('distance', '<', self::CLEAN_THRESHOLD)->count();

        return round($clean / $total, 4);
    }

    // ------------------------------------------------------------------ //
    //  Distance
    // ------------------------------------------------------------------ //

    /** Normalised edit distance in [0, 1]; 0 = identical after whitespace normalisation. */
    public function distance(string $original, string $edited): float
    {
        $a = $this->normalise($original);
        $b = $this->normalise($edited);

        if ($a === $b) {
            return 0.0;
        }
        if ($a === '' || $b === '') {
            return 1.0;
        }

        if (max(strlen($a), strlen($b)) <= self::CHAR_LIMIT) {
            $d = levenshtein($a, $b);

            return $this->clamp($d / max(strlen($a), strlen($b)));
        }

        $wa = preg_split('/\s+/u', $a) ?: [];
        $wb = preg_split('/\s+/u', $b) ?: [];
        if (max(count($wa), count($wb)) <= self::WORD_LIMIT) {
            $d = $this->sequenceDistance($wa, $wb, fn (string $x, string $y): float => $x === $y ? 0.0 : 1.0);

            return $this->clamp($d / max(count($wa), count($wb)));
        }

        $sa = $this->sentences($a);
        $sb = $this->sentences($b);
        $d = $this->sequenceDistance($sa, $sb, function (string $x, string $y): float {
            if ($x === $y) {
                return 0.0;
            }
            if (max(strlen($x), strlen($y)) > self::CHAR_LIMIT) {
                return 1.0;
            }

            return $this->clamp(levenshtein($x, $y) / max(strlen($x), strlen($y)));
        });

        return $this->clamp($d / max(count($sa), count($sb)));
    }

    /**
     * Deterministic categories the edit touched: subset of
     * facts | links | numbers | length | tone | ask (in that order).
     *
     * @return list<string>
     */
    public function categories(string $original, string $edited): array
    {
        $a = $this->normalise($original);
        $b = $this->normalise($edited);
        if ($a === $b) {
            return [];
        }

        $out = [];

        if ($this->entities($a) !== $this->entities($b)) {
            $out[] = 'facts';
        }
        if ($this->links($a) !== $this->links($b)) {
            $out[] = 'links';
        }
        if ($this->numbers($a) !== $this->numbers($b)) {
            $out[] = 'numbers';
        }
        if ($this->lengthChanged($a, $b)) {
            $out[] = 'length';
        }
        if ($this->toneSignature($a) !== $this->toneSignature($b)) {
            $out[] = 'tone';
        }
        if ($this->askSignature($a) !== $this->askSignature($b)) {
            $out[] = 'ask';
        }

        return $out;
    }

    // ------------------------------------------------------------------ //
    //  Detectors
    // ------------------------------------------------------------------ //

    /** @return list<string> sorted, lower-cased URLs */
    private function links(string $text): array
    {
        preg_match_all(self::URL_PATTERN, $text, $m);
        $urls = array_map(fn (string $u): string => strtolower(rtrim($u, '.,;:!?)')), $m[0] ?? []);
        $urls = array_values(array_unique($urls));
        sort($urls);

        return $urls;
    }

    /** @return list<string> sorted number tokens (URLs stripped first) */
    private function numbers(string $text): array
    {
        $text = (string) preg_replace(self::URL_PATTERN, ' ', $text);
        preg_match_all(self::NUMBER_PATTERN, $text, $m);
        $nums = array_map(fn (string $n): string => strtolower(str_replace(',', '', $n)), $m[0] ?? []);
        sort($nums);

        return array_values($nums);
    }

    private function lengthChanged(string $a, string $b): bool
    {
        $wa = $a === '' ? 0 : count(preg_split('/\s+/u', $a) ?: []);
        $wb = $b === '' ? 0 : count(preg_split('/\s+/u', $b) ?: []);
        if ($wa === 0 || $wb === 0) {
            return $wa !== $wb;
        }
        $ratio = $wb / $wa;

        return $ratio < 0.8 || $ratio > 1.25 || abs($wb - $wa) >= 40;
    }

    /** @return array<string, mixed> */
    private function askSignature(string $text): array
    {
        $lower = strtolower($text);
        preg_match_all(self::CTA_PATTERN, $lower, $m);

        return [
            'questions' => substr_count($lower, '?'),
            'cta' => count($m[0] ?? []) > 0,
        ];
    }

    /** @return array<string, mixed> word-choice proxy for tone */
    private function toneSignature(string $text): array
    {
        $lower = strtolower($text);
        $words = preg_split('/[^a-z\'\-]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordSet = array_flip($words);

        $hype = array_values(array_filter(self::HYPE_WORDS, fn (string $w): bool => isset($wordSet[$w])));
        sort($hype);

        $greeting = null;
        $head = substr($lower, 0, 40);
        foreach (self::GREETINGS as $g) {
            if (preg_match('/^\W*'.preg_quote($g, '/').'\b/', $head)) {
                $greeting = $g;
                break;
            }
        }

        $polite = array_values(array_filter(self::POLITENESS, fn (string $p): bool => str_contains($lower, $p)));
        sort($polite);

        $contractions = preg_match_all('/\b\w+(?:\'s|\'re|\'ll|\'ve|n\'t|\'d|\'m)\b/', $lower);

        return [
            'exclamations' => substr_count($text, '!'),
            'hype' => $hype,
            'greeting' => $greeting,
            'polite' => $polite,
            'contractions' => $contractions > 0,
        ];
    }

    /**
     * Capitalised tokens that do not start a sentence (names, companies,
     * products, months) — a cheap proxy for the facts the text asserts.
     *
     * @return list<string>
     */
    private function entities(string $text): array
    {
        $text = (string) preg_replace(self::URL_PATTERN, ' ', $text);
        $sentences = $this->sentences($text);
        $found = [];
        foreach ($sentences as $sentence) {
            $tokens = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $i => $token) {
                $clean = trim($token, " \t\n\r\0\x0B.,;:!?\"'()[]{}");
                if ($clean === '' || $i === 0) {
                    continue;
                }
                if (! preg_match('/^\p{Lu}[\p{L}\d&\'\-\.]*$/u', $clean)) {
                    continue;
                }
                if (in_array(strtolower($clean), self::NOT_ENTITIES, true)) {
                    continue;
                }
                $found[$clean] = true;
            }
        }
        $list = array_keys($found);
        sort($list);

        return $list;
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\s*\n\s*/u', "\n", $text);

        return trim($text);
    }

    /** @return list<string> */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [];
        $parts = array_map('trim', $parts);

        return array_values(array_filter($parts, fn (string $s): bool => $s !== ''));
    }

    /**
     * Generic edit distance over two token sequences with a substitution-cost
     * callback (insert/delete cost 1).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @param  callable(string, string): float  $cost
     */
    private function sequenceDistance(array $a, array $b, callable $cost): float
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return (float) $m;
        }
        if ($m === 0) {
            return (float) $n;
        }

        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cur[$j] = min(
                    $prev[$j] + 1,
                    $cur[$j - 1] + 1,
                    $prev[$j - 1] + $cost($a[$i - 1], $b[$j - 1]),
                );
            }
            $prev = $cur;
        }

        return (float) $prev[$m];
    }

    private function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
