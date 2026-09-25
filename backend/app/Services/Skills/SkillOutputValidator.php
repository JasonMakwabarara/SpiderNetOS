<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Models\Skill;

/**
 * Parses the model's reply and refuses anything the card or the FACTS do not
 * cover (ReplyPostFilter generalised to every skill):
 *
 *   - extracts the JSON object from prose / code fences, with one repair pass
 *     (trailing commas, smart quotes);
 *   - validates it against the card's primary output schema (required keys,
 *     enums, minItems/maxItems — so a wrong step count is a schema error —
 *     maxLength, types);
 *   - rejects banned phrases, links whose host is not in $facts['links'],
 *     and percentages / currency amounts that are not present in $facts;
 *   - checks next_steps[] name known or planned skills.
 */
class SkillOutputValidator
{
    public function __construct(private ?SkillRegistry $registry = null) {}

    /**
     * @param  SkillCard|Skill|string  $card  the card DTO; a `skills` row or a
     *                                        slug is resolved through the registry
     *                                        (the eval harness calls it that way)
     * @param  array<string, mixed>  $facts  as returned by SkillPromptBuilder::facts()
     *                                       (+ optional banned_phrases[], never_say[])
     */
    public function validate(SkillCard|Skill|string $card, string $raw, array $facts = []): ValidationResult
    {
        $card = $this->resolveCard($card);

        if (trim($raw) === '') {
            return ValidationResult::fail([['code' => 'empty', 'message' => 'The model returned nothing.']]);
        }

        ['data' => $data, 'repaired' => $repaired] = $this->extractJson($raw);
        if ($data === null) {
            return ValidationResult::fail([['code' => 'invalid_json', 'message' => 'No JSON object could be parsed from the reply.']]);
        }

        $errors = [];

        $kind = $card->primaryOutputKind();
        $schema = $kind !== null ? $card->outputSchema($kind) : null;
        if ($schema !== null) {
            foreach ((new SkillSchemaValidator($schema))->validate($data) as $message) {
                [$path, $detail] = array_pad(explode(': ', $message, 2), 2, $message);
                $errors[] = ['code' => 'schema', 'path' => $path, 'message' => $detail];
            }
        }

        $corpus = $this->strings($data);
        $text = implode("\n", array_map(fn (array $s) => $s['value'], $corpus));

        foreach ($this->bannedPhrases($card, $facts) as $phrase) {
            $regex = '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/iu';
            foreach ($corpus as $s) {
                if (preg_match($regex, $s['value']) === 1) {
                    $errors[] = ['code' => 'banned_phrase', 'path' => $s['path'], 'message' => "Contains the banned phrase \"{$phrase}\"."];
                    break;
                }
            }
        }

        foreach ($this->disallowedLinks($corpus, $facts) as [$path, $url]) {
            $errors[] = ['code' => 'link_not_allowed', 'path' => $path, 'message' => "Link not in the allowlist: {$url}"];
        }

        foreach ($this->unverifiedFigures($corpus, $facts) as [$path, $figure]) {
            $errors[] = ['code' => 'unverified_figure', 'path' => $path, 'message' => "Figure not in FACTS: {$figure}"];
        }

        foreach ($this->nextStepErrors($card, $data) as $error) {
            $errors[] = $error;
        }

        if ($errors !== []) {
            return ValidationResult::fail($errors, $data, $repaired);
        }

        return ValidationResult::ok($data, $repaired);
    }

    /** A `skills` row or a slug becomes the SkillCard DTO from the registry (or the row's own card). */
    private function resolveCard(SkillCard|Skill|string $card): SkillCard
    {
        if ($card instanceof SkillCard) {
            return $card;
        }
        $this->registry ??= new SkillRegistry;
        $slug = $card instanceof Skill ? (string) $card->slug : $card;
        $resolved = $this->registry->get($slug);
        if ($resolved !== null) {
            return $resolved;
        }
        if ($card instanceof Skill && is_array($card->card)) {
            return SkillCard::fromArray($card->card + ['id' => $card->slug], $this->registry->root().'/'.$card->slug);
        }

        throw new \InvalidArgumentException("Unknown skill \"{$slug}\".");
    }

    /**
     * First JSON object in the text, with one repair pass when strict parsing fails.
     *
     * @return array{data: ?array, repaired: bool}
     */
    public function extractJson(string $raw): array
    {
        $candidate = trim($raw);
        $candidate = preg_replace('/^```(?:json)?\s*|\s*```$/mi', '', $candidate) ?? $candidate;
        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');
        if ($start === false || $end === false || $end < $start) {
            return ['data' => null, 'repaired' => false];
        }
        $slice = substr($candidate, $start, $end - $start + 1);

        $decoded = json_decode($slice, true);
        if (is_array($decoded)) {
            return ['data' => $decoded, 'repaired' => false];
        }

        $repaired = $this->repair($slice);
        $decoded = json_decode($repaired, true);
        if (is_array($decoded)) {
            return ['data' => $decoded, 'repaired' => true];
        }

        return ['data' => null, 'repaired' => false];
    }

    private function repair(string $json): string
    {
        // Smart quotes → straight quotes (outer “ ” become string delimiters; ‘ ’ apostrophes stay text-safe).
        $json = str_replace(["\u{201C}", "\u{201D}", "\u{201E}"], '"', $json);
        $json = str_replace(["\u{2018}", "\u{2019}"], "'", $json);
        // Trailing commas before a closing bracket/brace.
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;
        // Line comments the model sometimes adds.
        $json = preg_replace('~^\s*//.*$~m', '', $json) ?? $json;
        // Unescaped control characters inside strings.
        $json = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/s', function (array $m): string {
            return str_replace(["\r\n", "\n", "\t"], ['\n', '\n', '\t'], $m[0]);
        }, $json) ?? $json;

        return $json;
    }

    /** @return list<string> */
    private function bannedPhrases(SkillCard $card, array $facts): array
    {
        $phrases = $card->bannedPhrases();
        foreach (['banned_phrases', 'never_say'] as $key) {
            foreach ((array) ($facts[$key] ?? []) as $phrase) {
                $phrase = trim((string) $phrase);
                if ($phrase !== '') {
                    $phrases[] = $phrase;
                }
            }
        }

        return array_values(array_unique(array_map('mb_strtolower', $phrases)));
    }

    /**
     * @param  list<array{path: string, value: string}>  $corpus
     * @return list<array{0: string, 1: string}>
     */
    private function disallowedLinks(array $corpus, array $facts): array
    {
        $allowedHosts = [];
        $links = array_merge(
            (array) ($facts['links'] ?? []),
            array_filter([(string) ($facts['join_url'] ?? ''), (string) ($facts['terms_url'] ?? '')]),
            array_filter([(string) ($facts['affiliate']['join_url'] ?? ''), (string) ($facts['affiliate']['terms_url'] ?? '')]),
        );
        foreach ($links as $link) {
            $host = $this->host((string) $link);
            if ($host !== '') {
                $allowedHosts[] = $host;
            }
        }

        $out = [];
        foreach ($corpus as $s) {
            if (preg_match_all('~https?://[^\s<>"\'\)\]]+~i', $s['value'], $m) === 0) {
                continue;
            }
            foreach ($m[0] as $url) {
                $host = $this->host(rtrim($url, '.,;:)'));
                if ($host === '' || ! in_array($host, $allowedHosts, true)) {
                    $out[] = [$s['path'], $url];
                }
            }
        }

        return $out;
    }

    /**
     * Every percentage and currency amount in the output must be a number that
     * appears somewhere in the FACTS (pricing, proof points, affiliate terms).
     *
     * @param  list<array{path: string, value: string}>  $corpus
     * @return list<array{0: string, 1: string}>
     */
    private function unverifiedFigures(array $corpus, array $facts): array
    {
        $allowed = [];
        foreach ($this->strings($facts) as $s) {
            foreach ($this->numbersIn($s['value']) as $n) {
                $allowed[$n] = true;
            }
        }
        foreach ($facts as $value) {
            if (is_int($value) || is_float($value)) {
                $allowed[$this->normalizeNumber((string) $value)] = true;
            }
        }

        $patterns = [
            '/(\d[\d,]*(?:[.,]\d+)?)\s*(?:%|percent\b|per cent\b)/iu',
            '/(?:\$|£|€|(?<![A-Za-z])R|USD|ZAR|GBP|EUR)\s?(\d[\d,]*(?:[.,]\d+)?)(?![\d,])/u',
            '/(\d[\d,]*(?:[.,]\d+)?)\s?(?:USD|ZAR|GBP|EUR|dollars|rand|pounds|euros)\b/iu',
        ];

        $out = [];
        foreach ($corpus as $s) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $s['value'], $m, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($m as $match) {
                    $normalized = $this->normalizeNumber($match[1]);
                    if (! isset($allowed[$normalized])) {
                        $out[] = [$s['path'], trim($match[0])];
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private function nextStepErrors(SkillCard $card, array $data): array
    {
        $steps = $data['next_steps'] ?? null;
        if (! is_array($steps)) {
            return [];
        }
        $errors = [];
        $allowDynamic = (bool) ($card->oneStepFurther['allow_dynamic'] ?? false);
        $templateIds = array_map(fn (array $s) => (string) ($s['id'] ?? ''), $card->nextStepTemplates());
        foreach ($steps as $i => $step) {
            if (! is_array($step)) {
                continue;
            }
            $skill = (string) ($step['skill'] ?? '');
            if ($skill !== '' && $this->registry && ! $this->registry->isKnownOrPlanned($skill)) {
                $errors[] = ['code' => 'schema', 'path' => "\$.next_steps[{$i}].skill", 'message' => "Unknown skill \"{$skill}\"."];
            }
            if (! $allowDynamic && ! in_array((string) ($step['id'] ?? ''), $templateIds, true)) {
                $errors[] = ['code' => 'schema', 'path' => "\$.next_steps[{$i}].id", 'message' => 'This card does not allow dynamic next steps.'];
            }
        }

        return $errors;
    }

    /** @return list<string> normalised numbers found in a string */
    private function numbersIn(string $text): array
    {
        if (preg_match_all('/\d[\d,]*(?:[.,]\d+)?/', $text, $m) === 0) {
            return [];
        }

        return array_map(fn (string $n) => $this->normalizeNumber($n), $m[0]);
    }

    private function normalizeNumber(string $number): string
    {
        $number = trim($number);
        // 1,800 / 12,500,000 → thousands separators; 1,5 → decimal comma.
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $number) === 1) {
            $number = str_replace(',', '', $number);
        } else {
            $number = str_replace(',', '.', $number);
        }
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return $number === '' ? '0' : $number;
    }

    private function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * Every string leaf with its JSON path.
     *
     * @return list<array{path: string, value: string}>
     */
    private function strings(mixed $node, string $path = '$'): array
    {
        if (is_string($node)) {
            return [['path' => $path, 'value' => $node]];
        }
        if (! is_array($node)) {
            return [];
        }
        $out = [];
        foreach ($node as $key => $value) {
            $childPath = is_int($key) ? "{$path}[{$key}]" : "{$path}.{$key}";
            foreach ($this->strings($value, $childPath) as $leaf) {
                $out[] = $leaf;
            }
        }

        return $out;
    }
}
