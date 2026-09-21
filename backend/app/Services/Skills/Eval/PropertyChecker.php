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
            $result = $this->one($spec, $output, $case, $validator);
            if ($result->status === 'failed') {
                $failed++;
            }
            if ($result->status !== 'skipped') {
                $evaluated++;
            }
            $results[] = [
                'type' => $spec['type'],
                'status' => $result->status,
                'passed' => $result->status === 'skipped' ? null : $result->status === 'passed',
                // The long-term contract. `status` says a check went red;
                // `reason` says which red it is, and those are not the same
                // fact — an executed rejection and a check that never ran wear
                // the same colour.
                'reason' => $result->reason->value,
                'detail' => $result->detail,
                'path' => $result->path,
                'evidence' => $result->evidence,
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
     * Three ways an assertion can fail to run at all, each distinguishable from
     * a violation and from each other.
     *
     * skills:validate already rejects all three before a model is called. They
     * are checked again here because the checker is reachable without going
     * through validation — from a test, from live mode, from a case loaded at
     * runtime — and a dispatch failure that reports itself as a violation is
     * how a deleted property kept its tests green.
     *
     * @param  array<string, mixed>  $p
     */
    private function one(array $p, mixed $output, ?EvalCase $case, ?array $validator): PropertyResult
    {
        $type = PropertyRegistry::get((string) $p['type']);

        if ($type === null) {
            return PropertyResult::dispatchFailure(Reason::UnknownProperty, 'unknown property type "'.$p['type'].'"');
        }

        if (! $type->isImplemented()) {
            return PropertyResult::dispatchFailure(
                Reason::PropertyNotImplemented,
                "property type \"{$type->name}\" is declared but has no handler yet",
            );
        }

        $why = $type->arg->reject(isset($p['arg']) && is_string($p['arg']) ? $p['arg'] : null, $type->name);
        if ($why !== null) {
            return PropertyResult::dispatchFailure(Reason::InvalidArgument, $why);
        }

        return $this->{$type->handler}(PropertyContext::for($p, $output, $case, $validator));
    }

    // ------------------------------------------------------------------ //
    //  Handlers — one per registered type. Registered in PropertyRegistry,
    //  which is the only thing that can reach them.
    // ------------------------------------------------------------------ //

    /** `has_key` */
    private function checkHasKey(PropertyContext $c): PropertyResult
    {
        $key = (string) ($c->p['key'] ?? $c->p['path'] ?? '');
        self::dig($c->output, $key, $exists);

        return $exists
            ? PropertyResult::pass("has {$key}", $key)
            : PropertyResult::fail(Reason::PathMissing, "missing key {$key}", $key);
    }

    /** `count` */
    private function checkCount(PropertyContext $c): PropertyResult
    {
        if (! is_array($c->target)) {
            return PropertyResult::fail(Reason::TypeMismatch, 'not a list at '.($c->examined() ?? '(root)'), $c->examined());
        }
        $n = count($c->target);
        foreach ([['equals', '≠'], ['min', '< min'], ['max', '> max']] as [$key, $sign]) {
            if (! isset($c->p[$key])) {
                continue;
            }
            $bound = (int) $c->p[$key];
            $violated = match ($key) {
                'equals' => $n !== $bound,
                'min' => $n < $bound,
                default => $n > $bound,
            };
            if ($violated) {
                return PropertyResult::fail(Reason::CountMismatch, "count {$n} {$sign} {$bound}", $c->examined());
            }
        }

        return PropertyResult::pass("count {$n}", $c->examined());
    }

    /** `enum` */
    private function checkEnum(PropertyContext $c): PropertyResult
    {
        $values = array_map('strval', (array) ($c->p['values'] ?? []));
        $actual = is_scalar($c->target) ? (string) $c->target : null;

        return $actual !== null && in_array($actual, $values, true)
            ? PropertyResult::pass("'{$actual}' ∈ enum", $c->examined())
            : PropertyResult::fail(Reason::EnumValueNotAllowed, "'".($actual ?? 'null')."' not in [".implode(', ', $values).']', $c->examined());
    }

    /** `cites_fact` */
    private function checkCitesFact(PropertyContext $c): PropertyResult
    {
        if (isset($c->p['fact'])) {
            return self::containsCi($c->text, (string) $c->p['fact'])
                ? PropertyResult::pass('cites fact', $c->examined())
                : PropertyResult::fail(Reason::FactNotCited, "does not cite \"{$c->p['fact']}\"", $c->examined());
        }
        $anyOf = array_map('strval', (array) ($c->p['any_of'] ?? []));
        if ($anyOf === [] && isset($c->p['from_brain']) && $c->case !== null) {
            $anyOf = self::factLines((string) ($c->case->fixtureBrain[(string) $c->p['from_brain']] ?? ''));
        }
        if ($anyOf === []) {
            // Nothing was compared, so nothing was established. Calling this a
            // violation would blame the output for a missing fixture.
            return PropertyResult::dependencyMissing(Reason::NoFactsAvailable, 'no facts available to cite (fixture file empty or missing)', 'failed', $c->examined());
        }
        foreach ($anyOf as $fact) {
            if (self::containsCi($c->text, $fact)) {
                return PropertyResult::pass('cites "'.mb_substr(trim($fact), 0, 40).'"', $c->examined());
            }
        }

        return PropertyResult::fail(Reason::FactNotCited, 'cites none of '.count($anyOf).' fact line(s) from '.($c->p['from_brain'] ?? 'the list'), $c->examined());
    }

    /** `max_length / min_length` */
    private function checkLength(PropertyContext $c): PropertyResult
    {
        $isMax = $c->type === 'max_length';
        $unit = ($c->p['unit'] ?? 'chars') === 'words' ? 'words' : 'chars';
        $len = $unit === 'words' ? str_word_count($c->text) : mb_strlen($c->text);
        $limit = (int) ($c->p[$isMax ? 'max' : 'min'] ?? 0);
        $detail = "{$len} {$unit} ".($isMax ? '≤' : '≥')." {$limit}";

        if ($isMax ? $len <= $limit : $len >= $limit) {
            return PropertyResult::pass($detail, $c->examined());
        }

        return PropertyResult::fail(
            $isMax ? Reason::MaxLengthExceeded : Reason::MinLengthNotMet,
            $detail.' violated',
            $c->examined(),
        );
    }

    /** `contains` */
    private function checkContains(PropertyContext $c): PropertyResult
    {
        return self::containsCi($c->text, (string) ($c->p['text'] ?? ''))
            ? PropertyResult::pass('contains text', $c->examined())
            : PropertyResult::fail(Reason::TextNotFound, "missing \"{$c->p['text']}\"", $c->examined());
    }

    /** `matches` */
    private function checkMatches(PropertyContext $c): PropertyResult
    {
        $pattern = (string) ($c->p['pattern'] ?? '');

        return $pattern !== '' && @preg_match($pattern, $c->text) === 1
            ? PropertyResult::pass('matches pattern', $c->examined())
            : PropertyResult::fail(Reason::PatternNotMatched, "no match for {$pattern}", $c->examined());
    }

    /** `valid_json` */
    private function checkValidJson(PropertyContext $c): PropertyResult
    {
        return is_array($c->output)
            ? PropertyResult::pass('JSON object')
            : PropertyResult::fail(Reason::NotJsonObject, 'output is not a JSON object');
    }

    /** `schema_valid` */
    private function checkSchemaValid(PropertyContext $c): PropertyResult
    {
        if ($c->validator === null || ! array_key_exists('ok', $c->validator) || $c->validator['ok'] === null) {
            return PropertyResult::dependencyMissing(Reason::ValidatorUnavailable, 'SkillOutputValidator not available', 'skipped');
        }
        if ($c->validator['ok']) {
            return PropertyResult::pass('validator ok');
        }
        $errors = array_values(array_map('strval', (array) ($c->validator['errors'] ?? [])));

        return PropertyResult::fail(Reason::SchemaInvalid, 'validator: '.implode('; ', array_slice($errors, 0, 3)), null, $errors);
    }

    /** `steps_count` */
    private function checkStepsCount(PropertyContext $c): PropertyResult
    {
        if ($c->steps === null) {
            return PropertyResult::fail(Reason::StepsMissing, 'no steps[] in output', 'steps');
        }
        $n = (int) $c->arg;
        $detail = count($c->steps).' step(s), expected '.$n;

        return count($c->steps) === $n
            ? PropertyResult::pass($detail, 'steps')
            : PropertyResult::fail(Reason::StepCountMismatch, $detail, 'steps');
    }

    /** `beats_in_order` */
    private function checkBeatsInOrder(PropertyContext $c): PropertyResult
    {
        if ($c->steps === null) {
            return PropertyResult::fail(Reason::StepsMissing, 'no steps[] in output', 'steps');
        }
        $expected = array_values(array_filter(array_map('trim', explode(',', (string) $c->arg)), fn ($s) => $s !== ''));
        $actual = array_map(fn ($s) => is_array($s) ? (string) ($s['beat'] ?? '') : '', $c->steps);

        return $actual === $expected
            ? PropertyResult::pass('beats '.implode(',', $actual), 'steps')
            : PropertyResult::fail(Reason::BeatOrderMismatch, 'beats '.implode(',', $actual).' ≠ '.implode(',', $expected), 'steps');
    }

    /** `subjects_per_step` */
    private function checkSubjectsPerStep(PropertyContext $c): PropertyResult
    {
        if ($c->steps === null) {
            return PropertyResult::fail(Reason::StepsMissing, 'no steps[] in output', 'steps');
        }
        $n = (int) $c->arg;
        $offenders = [];
        foreach ($c->steps as $i => $step) {
            $subjects = is_array($step) ? ($step['subjects'] ?? null) : null;
            $have = is_array($subjects) ? count($subjects) : 0;
            if (! is_array($subjects) || $have !== $n) {
                $offenders[] = 'steps.'.$i.'.subjects has '.$have;
            }
        }
        if ($offenders !== []) {
            return PropertyResult::fail(Reason::SubjectCountMismatch, $offenders[0].', expected '.$n, 'steps', $offenders);
        }

        return PropertyResult::pass($n.' subjects per step', 'steps');
    }

    /** `single_cta_per_step` */
    private function checkSingleCtaPerStep(PropertyContext $c): PropertyResult
    {
        if ($c->steps === null) {
            return PropertyResult::fail(Reason::StepsMissing, 'no steps[] in output', 'steps');
        }
        foreach ($c->steps as $i => $step) {
            $cta = is_array($step) ? ($step['cta'] ?? null) : null;
            if (! is_string($cta) || trim($cta) === '') {
                return PropertyResult::fail(Reason::CtaMissing, 'step '.$i.' has no cta', 'steps.'.$i.'.cta');
            }
            // `$step` is known to be an array here: a non-array would have made
            // `$cta` null and returned above.
            $body = (string) ($step['body'] ?? '');
            if (substr_count($body, '?') > 1) {
                return PropertyResult::fail(Reason::MultipleQuestionsInBody, 'step '.$i.' body asks more than one question', 'steps.'.$i.'.body');
            }
        }

        return PropertyResult::pass('one CTA per step', 'steps');
    }

    /** `no_unverified_figures` */
    private function checkNoUnverifiedFigures(PropertyContext $c): PropertyResult
    {
        $allowed = array_flip(self::numbers($c->case?->fixtureText() ?? ''));
        $bad = [];
        foreach (self::figures(self::stringLeaves($c->output)) as $figure) {
            if (! isset($allowed[$figure['number']])) {
                $bad[] = $figure['raw'];
            }
        }
        $bad = array_values(array_unique($bad));

        return $bad === []
            ? PropertyResult::pass('every figure is in the brain')
            : PropertyResult::fail(Reason::FigureNotVerified, 'not in the brain: '.implode(', ', $bad), null, $bad);
    }

    /** `links_allowlisted` */
    private function checkLinksAllowlisted(PropertyContext $c): PropertyResult
    {
        $allowedHosts = array_flip(array_map([self::class, 'host'], self::urls($c->case?->fixtureText() ?? '')));
        $bad = [];
        foreach (self::urls(self::text($c->output)) as $url) {
            if (! isset($allowedHosts[self::host($url)])) {
                $bad[] = $url;
            }
        }
        $bad = array_values(array_unique($bad));

        return $bad === []
            ? PropertyResult::pass('all links allowlisted')
            : PropertyResult::fail(Reason::LinkNotAllowlisted, 'off-allowlist: '.implode(', ', $bad), null, $bad);
    }

    /** `no_banned_phrases` */
    private function checkNoBannedPhrases(PropertyContext $c): PropertyResult
    {
        return $this->bannedCheck($c->text, $this->bannedPhrases($c->case, $c->p), $c->examined());
    }

    /** `mentions_proof_point` */
    private function checkMentionsProofPoint(PropertyContext $c): PropertyResult
    {
        $proofs = $this->proofPoints($c->case);
        if ($proofs === []) {
            // The fixture supplied nothing to match against, so the output was
            // never actually tested for a proof point.
            return PropertyResult::dependencyMissing(Reason::NoProofPointsAvailable, 'no proof points in the fixture brain', 'failed', $c->examined());
        }
        $lower = mb_strtolower($c->text);
        foreach ($proofs as $proof) {
            if (self::overlap($lower, $proof) >= 0.6) {
                return PropertyResult::pass('mentions "'.mb_substr($proof, 0, 40).'"', $c->examined());
            }
        }

        return PropertyResult::fail(Reason::ProofPointMissing, 'no proof point mentioned ('.count($proofs).' on file)', $c->examined());
    }

    /** `respects_never_say` */
    private function checkRespectsNeverSay(PropertyContext $c): PropertyResult
    {
        $forbidden = $this->neverSayTerms($c->case);
        if ($forbidden === []) {
            return PropertyResult::dependencyMissing(Reason::NoNeverSayRulesAvailable, 'no never-say rules in the fixture brain', 'skipped', $c->examined());
        }
        $hits = [];
        foreach ($forbidden as $term) {
            if (self::containsCi($c->text, $term)) {
                $hits[] = $term;
            }
        }
        if ($hits !== []) {
            return PropertyResult::fail(Reason::NeverSayViolated, 'mentions "'.$hits[0].'"', $c->examined(), $hits);
        }

        return PropertyResult::pass('respects never-say ('.count($forbidden).' term(s))', $c->examined());
    }

    /** `personalisation_slot_present` */
    private function checkPersonalisationSlotPresent(PropertyContext $c): PropertyResult
    {
        $n = max(1, (int) $c->arg);
        $found = 0;
        foreach ($c->steps ?? [] as $step) {
            if (is_array($step) && trim((string) ($step['personalisation_slot'] ?? '')) !== '') {
                $found++;
            }
        }
        $found += preg_match_all('/\{\{[^}]+\}\}|\[\[[^\]]+\]\]/', self::text($c->output));
        $detail = $found.' personalisation slot(s), expected ≥ '.$n;

        return $found >= $n
            ? PropertyResult::pass($detail)
            : PropertyResult::fail(Reason::PersonalisationSlotsInsufficient, $detail);
    }

    /** `blocked_missing_brain` */
    private function checkBlockedMissingBrain(PropertyContext $c): PropertyResult
    {
        $ref = (string) $c->arg;
        $missing = is_array($c->output) ? (array) ($c->output['missing'] ?? $c->output['missing_brain'] ?? $c->output['questions'] ?? []) : [];
        $refs = array_values(array_map(fn ($m) => is_array($m) ? ($m['path'] ?? '').(isset($m['section']) ? '#'.$m['section'] : '') : (string) $m, $missing));
        $blocked = is_array($c->output) && (($c->output['status'] ?? null) === 'blocked' || ($c->output['error'] ?? null) === 'missing_brain' || $missing !== []);

        return $blocked && in_array($ref, $refs, true)
            ? PropertyResult::pass('blocked on '.$ref, 'missing')
            : PropertyResult::fail(Reason::NotBlockedOnRef, 'not blocked on '.$ref, 'missing', array_map('strval', $refs));
    }

    // ------------------------------------------------------------------ //
    //  The boundary. Every path-addressed primitive passes through here, so
    //  none of them interprets an array, a fan-out, a missing key or an object
    //  on its own — which is how eight handlers would otherwise grow eight
    //  slightly different opinions about what "nothing there" means.
    // ------------------------------------------------------------------ //

    /**
     * Resolve the property's path, enforce its cardinality, then let the
     * primitive judge each resolved subject.
     *
     * `$offends` returns null when a subject satisfies the assertion, or a
     * short description of how it does not. Every offender is collected — not
     * the first — so a report cannot present one violation as the whole story.
     *
     * @param  callable(mixed, string): ?string  $offends
     */
    private function overSubjects(PropertyContext $c, Reason $violation, callable $offends, ?string $satisfied = null): PropertyResult
    {
        $matches = self::subjects($c);
        $path = self::pathOf($c);

        if (($early = self::refuse($c, $matches, $path)) !== null) {
            return $early;
        }

        $offenders = [];
        foreach ($matches->matches as $match) {
            $why = $offends($match['value'], $match['path']);
            if ($why !== null) {
                $offenders[] = $match['path'].': '.$why;
            }
        }

        if ($offenders !== []) {
            return PropertyResult::fail(
                $violation,
                count($offenders).' of '.$matches->count().' — '.$offenders[0],
                $path,
                $offenders,
            );
        }

        return PropertyResult::pass($satisfied ?? ($matches->count().' subject(s) satisfy '.$c->type), $path);
    }

    /**
     * The set flavour: the assertion is about the collection as a whole rather
     * than about each member, so it sees the resolved values together.
     *
     * @param  callable(list<mixed>): ?array{0: Reason, 1: string, 2: list<string>}  $judge
     */
    private function overSet(PropertyContext $c, callable $judge): PropertyResult
    {
        $matches = self::subjects($c);
        $path = self::pathOf($c);

        if (($early = self::refuse($c, $matches, $path)) !== null) {
            return $early;
        }

        $members = $matches->values();

        // The set operand, stated rather than inferred. `path: tags` resolves
        // ONE subject whose value is the collection; `path: tags[]` resolves one
        // subject per member. Both address the same set, so a lone list subject
        // is read as its members. Stringifying it instead made `["a","b"]` a
        // single fictitious member and `[]` the member `"[]"`.
        if (count($members) === 1 && is_array($members[0]) && array_is_list($members[0])) {
            $members = $members[0];
        }

        foreach ($members as $member) {
            if (is_array($member) || is_object($member)) {
                return PropertyResult::fail(
                    Reason::TypeMismatch,
                    'a set member is a '.get_debug_type($member).', and nesting is not part of this comparison',
                    $path,
                );
            }
        }

        $verdict = $judge($members);
        if ($verdict === null) {
            return PropertyResult::pass(count($members).' member(s) satisfy '.$c->type, $path);
        }

        return PropertyResult::fail($verdict[0], $verdict[1], $path, $verdict[2]);
    }

    /** One resolution for every primitive, so the count has a single source. */
    private static function subjects(PropertyContext $c): PathMatches
    {
        $path = self::pathOf($c);
        if ($path === '') {
            return PathMatches::matched([['path' => '(root)', 'value' => $c->output]]);
        }

        /** @var array<string, mixed> $where */
        $where = is_array($c->p['where'] ?? null) ? $c->p['where'] : [];
        $inputs = $c->case === null ? [] : $c->case->inputs;
        $matches = PropertyPath::resolve($c->output, $path, $where, $inputs);

        // An explicit null becomes one subject whose value is null. The
        // resolver keeps saying `NullAtPath`, which is how `is_null` tells it
        // from a field that was never there — the distinction survives, and the
        // primitive gets to use it.
        return $matches->outcome === PathOutcome::NullAtPath
            ? PathMatches::matched([['path' => $matches->stoppedAt ?? $path, 'value' => null]])
            : $matches;
    }

    private static function pathOf(PropertyContext $c): string
    {
        return isset($c->p['path']) && is_string($c->p['path']) ? $c->p['path'] : '';
    }

    /**
     * An addressing failure, or a match set the cardinality refuses.
     *
     * Null means the primitive may proceed. Everything else stops here, which
     * is what keeps "nothing resolved" from reaching a handler that would read
     * it as "nothing violated".
     */
    private static function refuse(PropertyContext $c, PathMatches $matches, string $path): ?PropertyResult
    {
        if (! $matches->isMatch()) {
            // A null is a value the model produced, not a failure to address
            // the document, so judging it belongs to the primitive. Refusing it
            // here would make `is_null` fail on exactly the state it asserts
            // whenever the path names the nullable field directly.
            if ($matches->outcome === PathOutcome::NullAtPath) {
                return null;
            }

            return PropertyResult::fail(
                self::reasonForOutcome($matches->outcome),
                $matches->outcome->value.' at '.($matches->stoppedAt ?? $path).' ('.($matches->found ?? 'nothing').')',
                $path,
                $matches->evidence()['malformed'],
            );
        }

        $rule = self::cardinalityFor($c);
        if (($why = $rule->reject($matches)) !== null) {
            return PropertyResult::fail(
                $why,
                $rule->value.' is not satisfied by '.$matches->count().' match(es)',
                $path,
                $matches->paths(),
            );
        }

        return null;
    }

    /**
     * The declared rule, with one derivation: a Root default addressing many
     * subjects becomes non-empty.
     *
     * `max_length` over `steps.0.body` is one site and `max_length` over
     * `items[].subject` is many, so cardinality is not purely a property of the
     * type — but the safe reading of "assert this over a collection" is that
     * the collection has to exist. A type that genuinely means something else
     * says so in the registry.
     */
    private static function cardinalityFor(PropertyContext $c): Cardinality
    {
        $type = PropertyRegistry::get($c->type);
        $declared = $type === null ? Cardinality::Root : $type->cardinality;
        if ($declared !== Cardinality::Root) {
            return $declared;
        }

        $fansOut = str_contains(self::pathOf($c), '[]')
            || (is_array($c->p['where'] ?? null) && $c->p['where'] !== []);

        return $fansOut ? Cardinality::EveryMatchNonEmpty : Cardinality::Root;
    }

    /**
     * Exhaustive on purpose. A new PathOutcome must not quietly acquire a
     * reason by falling through a default arm.
     */
    private static function reasonForOutcome(PathOutcome $outcome): Reason
    {
        return match ($outcome) {
            PathOutcome::MissingField => Reason::PathMissing,
            PathOutcome::NullAtPath => Reason::PathIsNull,
            PathOutcome::EmptyCollection => Reason::PathEmptyCollection,
            PathOutcome::NoSelectorMatch => Reason::SelectorMatchedNone,
            PathOutcome::TypeMismatch => Reason::TypeMismatch,
            PathOutcome::Inaccessible => Reason::PathInaccessible,
            PathOutcome::Matched => Reason::Satisfied,
        };
    }

    /** The subject itself, or one named member of it. */
    private static function memberOf(PropertyContext $c, mixed $subject): PathMatches
    {
        $field = isset($c->p['field']) && is_string($c->p['field']) ? $c->p['field'] : null;

        return $field === null
            ? PathMatches::matched([['path' => '(subject)', 'value' => $subject]])
            : PropertyPath::resolve($subject, $field);
    }

    private static function scalarText(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    // ------------------------------------------------------------------ //
    //  The eight primitives
    // ------------------------------------------------------------------ //

    /** `field` */
    private function checkField(PropertyContext $c): PropertyResult
    {
        return $this->overSubjects($c, Reason::FieldValueMismatch, function (mixed $subject) use ($c): ?string {
            $at = self::memberOf($c, $subject);
            if (! $at->isMatch()) {
                return 'no value ('.$at->outcome->value.')';
            }
            $actual = $at->sole();

            foreach (['equals', 'gte', 'lte'] as $comparison) {
                if (! array_key_exists($comparison, $c->p)) {
                    continue;
                }
                $expected = $c->p[$comparison];

                if ($comparison === 'equals') {
                    // Typed. scalarText(null) and scalarText('') are both '',
                    // so text equality accepted a null where "" was asserted.
                    if (self::typedKey($actual) !== self::typedKey($expected)) {
                        return 'is '.self::memberText($actual).', expected '.self::memberText($expected);
                    }

                    continue;
                }
                if (! is_numeric($actual) || ! is_numeric($expected)) {
                    return 'is "'.self::scalarText($actual).'", which is not a number to compare';
                }
                if ($comparison === 'gte' && (float) $actual < (float) $expected) {
                    return self::scalarText($actual).' < '.self::scalarText($expected);
                }
                if ($comparison === 'lte' && (float) $actual > (float) $expected) {
                    return self::scalarText($actual).' > '.self::scalarText($expected);
                }
            }

            return null;
        });
    }

    /** `is_null` */
    private function checkIsNull(PropertyContext $c): PropertyResult
    {
        return $this->overSubjects($c, Reason::ValueNotNull, function (mixed $subject) use ($c): ?string {
            $at = self::memberOf($c, $subject);

            // Present-and-null is the assertion. Absent is a different state
            // and says so, because every schema using this makes the key both
            // optional and nullable — so "it is null" and "it is not there" are
            // two answers a model can give and only one was asked about.
            return match ($at->outcome) {
                PathOutcome::NullAtPath => null,
                PathOutcome::Matched => $at->sole() === null ? null : 'is "'.self::scalarText($at->sole()).'", not null',
                PathOutcome::MissingField => 'is absent, which is not the same as null',
                default => 'could not be read ('.$at->outcome->value.')',
            };
        });
    }

    /** `not_contains` */
    private function checkNotContains(PropertyContext $c): PropertyResult
    {
        $needle = self::unquote((string) ($c->p['text'] ?? $c->arg ?? ''));

        return $this->overSubjects($c, Reason::TextPresent, function (mixed $subject) use ($needle): ?string {
            if ($needle === '') {
                return 'no text to look for';
            }

            return self::containsCi(self::text($subject), $needle) ? 'contains "'.$needle.'"' : null;
        });
    }

    /** `not_matches` */
    private function checkNotMatches(PropertyContext $c): PropertyResult
    {
        $pattern = (string) ($c->p['pattern'] ?? '');

        return $this->overSubjects($c, Reason::PatternMatched, function (mixed $subject) use ($pattern): ?string {
            if ($pattern === '' || @preg_match($pattern, '') === false) {
                return 'pattern "'.$pattern.'" is not usable';
            }

            return preg_match($pattern, self::text($subject)) === 1 ? 'matches '.$pattern : null;
        });
    }

    /** `not_empty` */
    private function checkNotEmpty(PropertyContext $c): PropertyResult
    {
        return $this->overSubjects($c, Reason::ValueEmpty, function (mixed $subject) use ($c): ?string {
            $at = self::memberOf($c, $subject);

            return match (true) {
                $at->outcome === PathOutcome::MissingField => 'is absent',
                $at->outcome === PathOutcome::NullAtPath => 'is null',
                $at->outcome === PathOutcome::EmptyCollection => 'is an empty collection',
                ! $at->isMatch() => 'could not be read ('.$at->outcome->value.')',
                // `false` and `0` are values, not emptiness. PHP's `empty()`
                // disagrees, which is why it is not used here.
                is_array($at->sole()) => $at->sole() === [] ? 'is an empty collection' : null,
                is_string($at->sole()) => trim($at->sole()) === '' ? 'is blank' : null,
                $at->sole() === null => 'is null',
                default => null,
            };
        });
    }

    /** `set_equals` */
    private function checkSetEquals(PropertyContext $c): PropertyResult
    {
        $expected = self::expectedSet($c);

        return $this->overSet($c, function (array $actual) use ($expected): ?array {
            $have = self::asSet($actual);
            $want = self::asSet($expected);

            // An empty actual set equals an empty expected set. Emptiness is
            // not the danger here; silently becoming a DIFFERENT set is.
            if (array_keys($have) === array_keys($want)) {
                return null;
            }
            $missing = array_values(array_diff_key($want, $have));
            $extra = array_values(array_diff_key($have, $want));

            return [
                Reason::SetMismatch,
                'missing ['.implode(', ', $missing).'], unexpected ['.implode(', ', $extra).']',
                array_merge(
                    array_map(static fn (string $v): string => 'missing: '.$v, $missing),
                    array_map(static fn (string $v): string => 'unexpected: '.$v, $extra),
                ),
            ];
        });
    }

    /** `set_includes` */
    private function checkSetIncludes(PropertyContext $c): PropertyResult
    {
        $expected = self::expectedSet($c);

        return $this->overSet($c, function (array $actual) use ($expected): ?array {
            $missing = array_values(array_diff_key(self::asSet($expected), self::asSet($actual)));

            return $missing === [] ? null : [
                Reason::SetMemberMissing,
                'missing ['.implode(', ', $missing).']',
                array_map(static fn (string $v): string => 'missing: '.$v, $missing),
            ];
        });
    }

    /** `set_excludes` */
    private function checkSetExcludes(PropertyContext $c): PropertyResult
    {
        $expected = self::expectedSet($c);

        return $this->overSet($c, function (array $actual) use ($expected): ?array {
            $have = self::asSet($actual);

            // "No block has role cta" over zero blocks establishes nothing.
            // The registry entry has always said so; now the code agrees. Note
            // this is the SET being empty, which is not the same fact as the
            // path resolving to no subjects - `path: blocks` over `[]` resolves
            // exactly one subject, so cardinality cannot catch this.
            if ($have === []) {
                return [
                    Reason::NoSubjectsToEvaluate,
                    'there are no members, so nothing was excluded',
                    [],
                ];
            }

            $present = array_values(array_intersect_key(self::asSet($expected), $have));

            return $present === [] ? null : [
                Reason::SetMemberForbidden,
                'contains ['.implode(', ', $present).']',
                array_map(static fn (string $v): string => 'forbidden: '.$v, $present),
            ];
        });
    }

    /**
     * The expected members, with YAML's types intact. Casting them to text here
     * is what let a declared `false` match the string "false".
     *
     * @return list<mixed>
     */
    private static function expectedSet(PropertyContext $c): array
    {
        $values = $c->p['values'] ?? $c->p['members'] ?? [];
        if (is_string($values)) {
            $values = explode(',', $values);
        }

        return array_map(static fn (mixed $v): mixed => is_string($v) ? trim($v) : $v, array_values((array) $values));
    }

    /**
     * The declared set contract, in one place:
     *
     *   - members come from the collection value or from the fan-out subjects;
     *   - scalars and null are members, nested arrays and objects are not;
     *   - duplicates are ignored, ordering is ignored;
     *   - comparison preserves type, so null, "", false and "false" are four
     *     different members;
     *   - the empty set is permitted or refused per operation, not globally.
     *
     * Keyed by type-preserving identity, valued by display text, and sorted so
     * evidence for the same input is always the same. The previous version
     * stringified members and then dropped every '' - which silently deleted
     * null members, because scalarText(null) is ''.
     *
     * @param  list<mixed>  $values
     * @return array<string, string>
     */
    private static function asSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $set[self::typedKey($value)] = self::memberText($value);
        }
        ksort($set);

        return $set;
    }

    /**
     * Identity that survives comparison. Two values are the same member only
     * when they are the same type and the same value.
     */
    private static function typedKey(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'bool:true' : 'bool:false',
            is_int($value) => 'int:'.$value,
            is_float($value) => 'float:'.$value,
            default => 'str:'.(string) $value,
        };
    }

    /** What a member is called in evidence, where '' and null must stay visible. */
    private static function memberText(mixed $value): string
    {
        return match (true) {
            $value === null => '<null>',
            $value === '' => '<empty string>',
            is_bool($value) => $value ? 'true' : 'false',
            default => '"'.(string) $value.'"',
        };
    }

    private static function unquote(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) >= 2) {
            $first = mb_substr($text, 0, 1);
            if (($first === '"' || $first === "'") && mb_substr($text, -1) === $first) {
                return mb_substr($text, 1, -1);
            }
        }

        return $text;
    }

    // ------------------------------------------------------------------ //
    //  Fixture-derived facts
    // ------------------------------------------------------------------ //

    /**
     * Every match, with its source. Not the first match.
     *
     * Returning one hit would let a brand-voice style word be the whole
     * explanation while an unsupported claim from the system defaults sits
     * unreported in the same output. Severity is assigned downstream from the
     * source, and a consequence engine cannot grade what it cannot attribute —
     * a style rule and a claim rule deserve different answers.
     *
     * @param  list<array{phrase: string, source: string}>  $phrases
     */
    private function bannedCheck(string $text, array $phrases, ?string $path = null): PropertyResult
    {
        $lower = mb_strtolower($text);
        $hits = [];
        foreach ($phrases as $entry) {
            $phrase = mb_strtolower(trim($entry['phrase']));
            if ($phrase === '') {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $lower) === 1) {
                $hits[$entry['source'].':'.$phrase] = true;
            }
        }
        $hits = array_keys($hits);

        if ($hits !== []) {
            return PropertyResult::fail(
                Reason::BannedPhrasePresent,
                'banned phrase present: '.implode(', ', $hits),
                $path,
                $hits,
            );
        }

        return PropertyResult::pass('no banned phrases ('.count($phrases).' rule(s))', $path);
    }

    /**
     * Three sources, kept apart: the property's own `phrases`, the quoted terms
     * after "Don't say" in brand/voice.md, and the sales-writing defaults every
     * card bans.
     *
     * The sources are tagged rather than flattened into one list because they
     * are not the same kind of rule. `leverage` from a brand voice is a style
     * preference; `guaranteed` from the defaults is an unsupported claim. Both
     * must block the draft; they must not carry the same consequence.
     *
     * This is also the defect the deleted `no_banned_phrase` shipped: the
     * singular read only `phrases`, so a case naming its own list silently lost
     * the brand and default rules — one character of difference between a
     * complete safety check and a partial one. Explicit phrases *add to* the
     * other two sources. They never replace them.
     *
     * @param  array<string, mixed>  $p
     * @return list<array{phrase: string, source: string}>
     */
    private function bannedPhrases(?EvalCase $case, array $p): array
    {
        $seen = [];
        $out = [];
        $add = function (string $phrase, string $source) use (&$seen, &$out): void {
            $key = mb_strtolower(trim($phrase));
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['phrase' => $phrase, 'source' => $source];
        };

        foreach ((array) ($p['phrases'] ?? []) as $phrase) {
            if (is_scalar($phrase)) {
                $add((string) $phrase, 'property');
            }
        }

        // Same parser the live run uses (SkillPromptBuilder::facts), so a rule
        // cannot mean one thing in an eval and another in production.
        foreach (VoiceRules::bannedPhrasesFrom($case?->fixtureBrain['brand/voice.md'] ?? null) as $term) {
            $add($term, 'brand');
        }

        foreach (self::defaultBannedPhrases() as $phrase) {
            $add($phrase, 'system');
        }

        return $out;
    }

    /** @return list<string> */
    private static function defaultBannedPhrases(): array
    {
        $card = 'App\\Services\\Skills\\SkillCard';
        if (class_exists($card) && defined($card.'::DEFAULT_BANNED_PHRASES')) {
            return array_map('strval', (array) constant($card.'::DEFAULT_BANNED_PHRASES'));
        }

        return ['guaranteed results', 'guaranteed', 'risk-free', 'limited time only', 'act now', 'as an ai', 'i hope this email finds you well', 'just checking in', 'circling back'];
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

        return array_values(array_unique(array_map(fn (string $u) => rtrim($u, '.,;:)'), $m[0])));
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
