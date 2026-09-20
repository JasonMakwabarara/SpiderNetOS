<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

use App\Services\Skills\VoiceRules;

/**
 * Deterministic property checks over a skill output (plan D8 #14).
 *
 * Two spellings are accepted:
 *   array form  — {type: has_key|count|enum|no_banned_phrase|cites_fact|max_length|min_length|contains|matches, path?, …}
 *   string form — the names the cards use: valid_json, schema_valid, steps_count:N,
 *                 beats_in_order:a,b,c, subjects_per_step:N, single_cta_per_step,
 *                 no_unverified_figures, links_allowlisted, no_banned_phrases,
 *                 mentions_proof_point, respects_never_say, personalisation_slot_present:N,
 *                 blocked_missing_brain:path#Section
 * String-form checks read the case's fixture brain (offer links and proof
 * points, brand "Don't say", people never-say). `schema_valid` is the
 * SkillOutputValidator verdict passed in by EvalRunner; without one it is
 * skipped rather than failed.
 */
class PropertyChecker
{
    private const URL_PATTERN = '~https?://[^\s<>"\'\)\]]+~i';

    /**
     * @param  list<string|array<string, mixed>>  $properties
     * @param  array{ok: ?bool, errors?: list<string>}|null  $validator  SkillOutputValidator verdict (schema_valid)
     * @return array{passed: bool, failed: int, evaluated: int, results: list<array{type: string, status: string, passed: ?bool, detail: string, property: mixed}>}
     */
    public function check(array $properties, mixed $output, ?EvalCase $case = null, ?array $validator = null): array
    {
        $results = [];
        $failed = 0;
        $evaluated = 0;

        foreach ($properties as $property) {
            $spec = self::normalise($property);
            [$status, $detail] = $this->one($spec, $output, $case, $validator);
            if ($status === 'failed') {
                $failed++;
            }
            if ($status !== 'skipped') {
                $evaluated++;
            }
            $results[] = [
                'type' => $spec['type'],
                'status' => $status,
                'passed' => $status === 'skipped' ? null : $status === 'passed',
                'detail' => $detail,
                'property' => $property,
            ];
        }

        return ['passed' => $failed === 0, 'failed' => $failed, 'evaluated' => $evaluated, 'results' => $results];
    }

    /** `name:arg` → {type, arg}; arrays pass through with a `type`. */
    public static function normalise(string|array $property): array
    {
        if (is_array($property)) {
            return ['type' => (string) ($property['type'] ?? '')] + $property;
        }
        [$type, $arg] = array_pad(explode(':', trim($property), 2), 2, null);

        return ['type' => trim((string) $type), 'arg' => $arg === null ? null : trim($arg)];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{0: string, 1: string} status (passed|failed|skipped), detail
     */
    private function one(array $p, mixed $output, ?EvalCase $case, ?array $validator): array
    {
        $type = PropertyRegistry::get((string) $p['type']);

        if ($type === null) {
            return ['failed', 'unknown property type "'.$p['type'].'"'];
        }

        if (! $type->isImplemented()) {
            return ['failed', "property type \"{$type->name}\" is declared but has no handler yet"];
        }

        return $this->{$type->handler}(PropertyContext::for($p, $output, $case, $validator));
    }

    // ------------------------------------------------------------------ //
    //  Handlers — one per registered type. Registered in PropertyRegistry,
    //  which is the only thing that can reach them.
    // ------------------------------------------------------------------ //

    /** `has_key` */
    private function checkHasKey(PropertyContext $c): array
    {

        $key = (string) ($c->p['key'] ?? $c->p['path'] ?? '');
        self::dig($c->output, $key, $exists);

        return [$exists ? 'passed' : 'failed', $exists ? "has {$key}" : "missing key {$key}"];
    }

    /** `count` */
    private function checkCount(PropertyContext $c): array
    {

        if (! is_array($c->target)) {
            return ['failed', 'not a list at '.($c->p['path'] ?? '(root)')];
        }
        $n = count($c->target);
        if (isset($c->p['equals']) && $n !== (int) $c->p['equals']) {
            return ['failed', "count {$n} ≠ {$c->p['equals']}"];
        }
        if (isset($c->p['min']) && $n < (int) $c->p['min']) {
            return ['failed', "count {$n} < min {$c->p['min']}"];
        }
        if (isset($c->p['max']) && $n > (int) $c->p['max']) {
            return ['failed', "count {$n} > max {$c->p['max']}"];
        }

        return ['passed', "count {$n}"];
    }

    /** `enum` */
    private function checkEnum(PropertyContext $c): array
    {

        $values = array_map('strval', (array) ($c->p['values'] ?? []));
        $actual = is_scalar($c->target) ? (string) $c->target : null;
        $ok = $actual !== null && in_array($actual, $values, true);

        return [$ok ? 'passed' : 'failed', $ok ? "'{$actual}' ∈ enum" : "'".($actual ?? 'null')."' not in [".implode(', ', $values).']'];
    }

    /** `cites_fact` */
    private function checkCitesFact(PropertyContext $c): array
    {

        if (isset($c->p['fact'])) {
            $ok = self::containsCi($c->text, (string) $c->p['fact']);

            return [$ok ? 'passed' : 'failed', $ok ? 'cites fact' : "does not cite \"{$c->p['fact']}\""];
        }
        $anyOf = array_map('strval', (array) ($c->p['any_of'] ?? []));
        if ($anyOf === [] && isset($c->p['from_brain']) && $c->case !== null) {
            $anyOf = self::factLines((string) ($c->case->fixtureBrain[(string) $c->p['from_brain']] ?? ''));
        }
        if ($anyOf === []) {
            return ['failed', 'no facts available to cite (fixture file empty or missing)'];
        }
        foreach ($anyOf as $fact) {
            if (self::containsCi($c->text, $fact)) {
                return ['passed', 'cites "'.mb_substr(trim($fact), 0, 40).'"'];
            }
        }

        return ['failed', 'cites none of '.count($anyOf).' fact line(s) from '.($c->p['from_brain'] ?? 'the list')];
    }

    /** `max_length / min_length` */
    private function checkLength(PropertyContext $c): array
    {

        $unit = ($c->p['unit'] ?? 'chars') === 'words' ? 'words' : 'chars';
        $len = $unit === 'words' ? str_word_count($c->text) : mb_strlen($c->text);
        $limit = (int) ($c->p[$c->type === 'max_length' ? 'max' : 'min'] ?? 0);
        $ok = $c->type === 'max_length' ? $len <= $limit : $len >= $limit;

        return [$ok ? 'passed' : 'failed', "{$len} {$unit} ".($c->type === 'max_length' ? '≤' : '≥')." {$limit}".($ok ? '' : ' violated')];
    }

    /** `contains` */
    private function checkContains(PropertyContext $c): array
    {

        $ok = self::containsCi($c->text, (string) ($c->p['text'] ?? ''));

        return [$ok ? 'passed' : 'failed', $ok ? 'contains text' : "missing \"{$c->p['text']}\""];
    }

    /** `matches` */
    private function checkMatches(PropertyContext $c): array
    {

        $pattern = (string) ($c->p['pattern'] ?? '');
        $ok = $pattern !== '' && @preg_match($pattern, $c->text) === 1;

        return [$ok ? 'passed' : 'failed', $ok ? 'matches pattern' : "no match for {$pattern}"];
    }

    /** `valid_json` */
    private function checkValidJson(PropertyContext $c): array
    {

        return [is_array($c->output) ? 'passed' : 'failed', is_array($c->output) ? 'JSON object' : 'output is not a JSON object'];
    }

    /** `schema_valid` */
    private function checkSchemaValid(PropertyContext $c): array
    {

        if ($c->validator === null || ! array_key_exists('ok', $c->validator) || $c->validator['ok'] === null) {
            return ['skipped', 'SkillOutputValidator not available'];
        }

        return [$c->validator['ok'] ? 'passed' : 'failed', $c->validator['ok'] ? 'validator ok' : 'validator: '.implode('; ', array_slice((array) ($c->validator['errors'] ?? []), 0, 3))];
    }

    /** `steps_count` */
    private function checkStepsCount(PropertyContext $c): array
    {

        if ($c->steps === null) {
            return ['failed', 'no steps[] in output'];
        }
        $n = (int) $c->arg;
        $ok = count($c->steps) === $n;

        return [$ok ? 'passed' : 'failed', count($c->steps)." step(s), expected {$n}"];
    }

    /** `beats_in_order` */
    private function checkBeatsInOrder(PropertyContext $c): array
    {

        if ($c->steps === null) {
            return ['failed', 'no steps[] in output'];
        }
        $expected = array_values(array_filter(array_map('trim', explode(',', (string) $c->arg)), fn ($s) => $s !== ''));
        $actual = array_map(fn ($s) => is_array($s) ? (string) ($s['beat'] ?? '') : '', $c->steps);
        $ok = $actual === $expected;

        return [$ok ? 'passed' : 'failed', 'beats '.implode(',', $actual).($ok ? '' : ' ≠ '.implode(',', $expected))];
    }

    /** `subjects_per_step` */
    private function checkSubjectsPerStep(PropertyContext $c): array
    {

        if ($c->steps === null) {
            return ['failed', 'no steps[] in output'];
        }
        $n = (int) $c->arg;
        foreach ($c->steps as $i => $step) {
            $subjects = is_array($step) ? ($step['subjects'] ?? null) : null;
            if (! is_array($subjects) || count($subjects) !== $n) {
                return ['failed', "step {$i} has ".(is_array($subjects) ? count($subjects) : 0)." subject(s), expected {$n}"];
            }
        }

        return ['passed', "{$n} subjects per step"];
    }

    /** `single_cta_per_step` */
    private function checkSingleCtaPerStep(PropertyContext $c): array
    {

        if ($c->steps === null) {
            return ['failed', 'no steps[] in output'];
        }
        foreach ($c->steps as $i => $step) {
            $cta = is_array($step) ? ($step['cta'] ?? null) : null;
            if (! is_string($cta) || trim($cta) === '') {
                return ['failed', "step {$i} has no cta"];
            }
            $body = is_array($step) ? (string) ($step['body'] ?? '') : '';
            if (substr_count($body, '?') > 1) {
                return ['failed', "step {$i} body asks more than one question"];
            }
        }

        return ['passed', 'one CTA per step'];
    }

    /** `no_unverified_figures` */
    private function checkNoUnverifiedFigures(PropertyContext $c): array
    {

        $allowed = array_flip(self::numbers($c->case?->fixtureText() ?? ''));
        $bad = [];
        foreach (self::figures(self::stringLeaves($c->output)) as $figure) {
            if (! isset($allowed[$figure['number']])) {
                $bad[] = $figure['raw'];
            }
        }

        return [$bad === [] ? 'passed' : 'failed', $bad === [] ? 'every figure is in the brain' : 'not in the brain: '.implode(', ', array_unique($bad))];
    }

    /** `links_allowlisted` */
    private function checkLinksAllowlisted(PropertyContext $c): array
    {

        $allowedHosts = array_flip(array_map([self::class, 'host'], self::urls($c->case?->fixtureText() ?? '')));
        $bad = [];
        foreach (self::urls(self::text($c->output)) as $url) {
            if (! isset($allowedHosts[self::host($url)])) {
                $bad[] = $url;
            }
        }

        return [$bad === [] ? 'passed' : 'failed', $bad === [] ? 'all links allowlisted' : 'off-allowlist: '.implode(', ', array_unique($bad))];
    }

    /** `no_banned_phrases` */
    private function checkNoBannedPhrases(PropertyContext $c): array
    {

        return $this->bannedCheck($c->text, $this->bannedPhrases($c->case, $c->p));
    }

    /** `mentions_proof_point` */
    private function checkMentionsProofPoint(PropertyContext $c): array
    {

        $proofs = $this->proofPoints($c->case);
        if ($proofs === []) {
            return ['failed', 'no proof points in the fixture brain'];
        }
        $lower = mb_strtolower($c->text);
        foreach ($proofs as $proof) {
            if (self::overlap($lower, $proof) >= 0.6) {
                return ['passed', 'mentions "'.mb_substr($proof, 0, 40).'"'];
            }
        }

        return ['failed', 'no proof point mentioned ('.count($proofs).' on file)'];
    }

    /** `respects_never_say` */
    private function checkRespectsNeverSay(PropertyContext $c): array
    {

        $forbidden = $this->neverSayTerms($c->case);
        if ($forbidden === []) {
            return ['skipped', 'no never-say rules in the fixture brain'];
        }
        foreach ($forbidden as $term) {
            if (self::containsCi($c->text, $term)) {
                return ['failed', "mentions \"{$term}\""];
            }
        }

        return ['passed', 'respects never-say ('.count($forbidden).' term(s))'];
    }

    /** `personalisation_slot_present` */
    private function checkPersonalisationSlotPresent(PropertyContext $c): array
    {

        $n = max(1, (int) $c->arg);
        $found = 0;
        foreach ($c->steps ?? [] as $step) {
            if (is_array($step) && trim((string) ($step['personalisation_slot'] ?? '')) !== '') {
                $found++;
            }
        }
        $found += preg_match_all('/\{\{[^}]+\}\}|\[\[[^\]]+\]\]/', self::text($c->output));
        $ok = $found >= $n;

        return [$ok ? 'passed' : 'failed', "{$found} personalisation slot(s), expected ≥ {$n}"];
    }

    /** `blocked_missing_brain` */
    private function checkBlockedMissingBrain(PropertyContext $c): array
    {

        $ref = (string) $c->arg;
        $missing = is_array($c->output) ? (array) ($c->output['missing'] ?? $c->output['missing_brain'] ?? $c->output['questions'] ?? []) : [];
        $refs = array_map(fn ($m) => is_array($m) ? ($m['path'] ?? '').(isset($m['section']) ? '#'.$m['section'] : '') : (string) $m, $missing);
        $blocked = is_array($c->output) && (($c->output['status'] ?? null) === 'blocked' || ($c->output['error'] ?? null) === 'missing_brain' || $missing !== []);
        $ok = $blocked && in_array($ref, $refs, true);

        return [$ok ? 'passed' : 'failed', $ok ? "blocked on {$ref}" : "not blocked on {$ref}"];
    }

    // ------------------------------------------------------------------ //
    //  Fixture-derived facts
    // ------------------------------------------------------------------ //

    /** @return array{0: string, 1: string} */
    private function bannedCheck(string $text, array $phrases): array
    {
        $lower = mb_strtolower($text);
        foreach ($phrases as $phrase) {
            $phrase = mb_strtolower(trim((string) $phrase));
            if ($phrase === '') {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $lower) === 1) {
                return ['failed', "banned phrase present: \"{$phrase}\""];
            }
        }

        return ['passed', 'no banned phrases'];
    }

    /**
     * Explicit phrases on the property + quoted terms after "Don't say" in
     * brand/voice.md + the sales-writing defaults every card bans.
     *
     * @return list<string>
     */
    private function bannedPhrases(?EvalCase $case, array $p): array
    {
        $phrases = array_map('strval', (array) ($p['phrases'] ?? []));

        // Same parser the live run uses (SkillPromptBuilder::facts), so a rule
        // cannot mean one thing in an eval and another in production.
        foreach (VoiceRules::bannedPhrasesFrom($case?->fixtureBrain['brand/voice.md'] ?? null) as $term) {
            $phrases[] = $term;
        }

        if (class_exists('App\\Services\\Skills\\SkillCard') && defined('App\\Services\\Skills\\SkillCard::DEFAULT_BANNED_PHRASES')) {
            foreach ((array) constant('App\\Services\\Skills\\SkillCard::DEFAULT_BANNED_PHRASES') as $phrase) {
                $phrases[] = (string) $phrase;
            }
        } else {
            $phrases = array_merge($phrases, ['guaranteed results', 'guaranteed', 'risk-free', 'limited time only', 'act now', 'as an ai', 'i hope this email finds you well', 'just checking in', 'circling back']);
        }

        return array_values(array_unique($phrases));
    }

    /** @return list<string> lower-cased proof points (frontmatter proof_points[] + the Proof section lines) */
    private function proofPoints(?EvalCase $case): array
    {
        if ($case === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($case->fixtureFrontmatter('offer/offer.md')['proof_points'] ?? []) as $proof) {
            if (is_scalar($proof) && trim((string) $proof) !== '') {
                $out[] = mb_strtolower(trim((string) $proof));
            }
        }
        $section = $case->fixtureSection('offer/offer.md', 'Proof');
        if ($section !== null) {
            foreach (self::factLines($section) as $line) {
                $out[] = mb_strtolower($line);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * "Never offer a discount." → "discount"; "Never compare us to a named competitor." → "compare us to a named competitor".
     *
     * @return list<string>
     */
    private function neverSayTerms(?EvalCase $case): array
    {
        // Same parser the live run uses, for the same reason as bannedPhrases().
        return VoiceRules::neverSayFrom($case?->fixtureBrain['people/user.md'] ?? null);
    }

    // ------------------------------------------------------------------ //
    //  Text helpers
    // ------------------------------------------------------------------ //

    /** Dotted-path lookup into arrays (`steps.0.body`); sets $exists. */
    public static function dig(mixed $data, string $path, ?bool &$exists = null): mixed
    {
        $exists = true;
        if ($path === '') {
            return $data;
        }
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }
            if (is_object($current) && isset($current->{$segment})) {
                $current = $current->{$segment};

                continue;
            }
            $exists = false;

            return null;
        }

        return $current;
    }

    public static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return implode("\n", self::stringLeaves($value));
    }

    /** @return list<string> every string leaf of a nested array (or the string itself) */
    public static function stringLeaves(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            foreach (self::stringLeaves($v) as $leaf) {
                $out[] = $leaf;
            }
        }

        return $out;
    }

    /**
     * Fact lines of a fixture brain file: non-empty, non-heading lines of
     * at least 8 characters (bullets and frontmatter stripped).
     *
     * @return list<string>
     */
    public static function factLines(string $markdown): array
    {
        $out = [];
        $inFrontmatter = false;
        foreach (preg_split('/\r?\n/', $markdown) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '---') {
                $inFrontmatter = ! $inFrontmatter && $i === 0 ? true : false;

                continue;
            }
            if ($inFrontmatter || $line === '' || str_starts_with($line, '#') || str_starts_with($line, '<!--')) {
                continue;
            }
            $line = (string) preg_replace('/^[-*•]\s+/', '', $line);
            $line = (string) preg_replace('/^[a-z_]+:\s+/i', '', $line);
            $line = trim($line, '"');
            if (mb_strlen($line) >= 8) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return list<string> normalised numbers in a text (1,800 → 1800; 1,5 → 1.5) */
    public static function numbers(string $text): array
    {
        if (preg_match_all('/\d[\d,]*(?:[.,]\d+)?/', $text, $m) === 0) {
            return [];
        }

        return array_values(array_unique(array_map([self::class, 'normaliseNumber'], $m[0])));
    }

    /**
     * Percentages and currency amounts in string leaves.
     *
     * @param  list<string>  $leaves
     * @return list<array{raw: string, number: string}>
     */
    public static function figures(array $leaves): array
    {
        $patterns = [
            '/(\d[\d,]*(?:[.,]\d+)?)\s*(?:%|percent\b|per cent\b)/iu',
            '/(?:\$|£|€|(?<![A-Za-z])R|USD|ZAR|GBP|EUR)\s?(\d[\d,]*(?:[.,]\d+)?)(?![\d,])/u',
            '/(\d[\d,]*(?:[.,]\d+)?)\s?(?:USD|ZAR|GBP|EUR|dollars|rand|pounds|euros)\b/iu',
        ];
        $out = [];
        foreach ($leaves as $leaf) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $leaf, $m, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($m as $match) {
                    $out[] = ['raw' => trim($match[0]), 'number' => self::normaliseNumber($match[1])];
                }
            }
        }

        return $out;
    }

    public static function normaliseNumber(string $number): string
    {
        $number = trim($number);
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

    /** @return list<string> */
    public static function urls(string $text): array
    {
        preg_match_all(self::URL_PATTERN, $text, $m);

        return array_values(array_unique(array_map(fn (string $u) => rtrim($u, '.,;:)'), $m[0] ?? [])));
    }

    public static function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return (string) preg_replace('/^www\./', '', $host);
    }

    private static function containsCi(string $haystack, string $needle): bool
    {
        $needle = trim($needle);

        return $needle !== '' && mb_stripos($haystack, $needle) !== false;
    }

    /** Share of a proof point's distinctive words (≥ 4 chars) present in the text. */
    private static function overlap(string $lowerText, string $lowerProof): float
    {
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $lowerProof) ?: [], fn ($w) => mb_strlen($w) >= 4)));
        if ($words === []) {
            return str_contains($lowerText, $lowerProof) ? 1.0 : 0.0;
        }
        $hit = 0;
        foreach ($words as $w) {
            if (str_contains($lowerText, $w)) {
                $hit++;
            }
        }

        return $hit / count($words);
    }
}
