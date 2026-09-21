<?php

declare(strict_types=1);

namespace Tests\Unit\Skills\Eval;

use App\Services\Skills\Eval\EvalCase;
use App\Services\Skills\Eval\PropertyChecker;
use App\Services\Skills\Eval\PropertyRegistry;
use App\Services\Skills\Eval\Reason;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every implemented property type, passing and failing — and every failure
 * naming which failure it is.
 *
 * The earlier version of this file asserted only the colour. `no_banned_phrase`
 * proved that is not a contract: before it was deleted, its negative meant "the
 * known check found prohibited content"; afterwards the identical assertion
 * still passed and meant "an unknown property could not execute". A suite
 * reading only `failed` accepts both, while one of them has stopped testing
 * content safety.
 *
 * So each negative pins four things — the type is registered and implemented,
 * the status is `failed`, the `reason` is the intended one, and `path` is the
 * part of the output actually examined. A handler that returns `passed`
 * unconditionally fails on the second; a handler that goes red for the wrong
 * cause fails on the third; a handler reading the wrong field fails on the
 * fourth.
 *
 * Detail strings are deliberately *not* asserted here beyond the substrings
 * that carry meaning. Freezing them byte-for-byte was the right instrument for
 * the switch-to-registry transposition, where nothing should have changed; it
 * is the wrong instrument for a long-lived contract, where it would make
 * rewording a diagnostic a breaking change.
 */
class PropertyCheckerTest extends TestCase
{
    /** The types with a handler, read from the registry that dispatches them. */
    private static function implemented(): array
    {
        return PropertyRegistry::implemented();
    }

    #[DataProvider('examples')]
    public function test_property_behaves_as_declared(
        string $type,
        string|array $property,
        mixed $output,
        string $expected,
        Reason $reason,
        ?string $path = null,
        ?array $validator = null,
    ): void {
        $this->assertTrue(
            PropertyRegistry::get($type)?->isImplemented() ?? false,
            "{$type} is not an implemented registry entry, so this example cannot be testing it",
        );

        $row = (new PropertyChecker)->check([$property], $output, self::case(), $validator)['results'][0];

        $this->assertSame($expected, $row['status'], "{$type}: {$row['detail']}");
        $this->assertSame($type, $row['type']);
        $this->assertSame(
            $reason->value,
            $row['reason'],
            "{$type}: expected reason {$reason->value}, got {$row['reason']} — {$row['detail']}",
        );
        $this->assertSame($path, $row['path'], "{$type} examined the wrong part of the output");
    }

    /**
     * Both directions of the coverage rule, which is the point of the registry.
     *
     * Not "every type is used somewhere" — the corpus is allowed to ignore a
     * primitive. The rule is that the provider's own type set is exactly the
     * implemented set, so adding an arm without an example, or leaving an
     * example behind after deleting an arm, both fail here.
     */
    public function test_every_implemented_type_has_a_passing_and_a_failing_example(): void
    {
        $seen = [];
        foreach (self::examples() as $label => $row) {
            $seen[$row[0]][] = str_contains($label, 'fails') ? 'failed' : 'passed';
        }

        $this->assertSame(
            [],
            array_values(array_diff(self::implemented(), array_keys($seen))),
            'implemented but no example in this provider',
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($seen), self::implemented())),
            'example for a type PropertyChecker does not implement',
        );

        foreach ($seen as $type => $directions) {
            $this->assertContains('passed', $directions, "{$type} has no passing example");
            $this->assertContains('failed', $directions, "{$type} has no failing example");
        }
    }

    /**
     * No negative may be explained by "the check did not run".
     *
     * This is the rule `no_banned_phrase` broke. A negative whose reason is a
     * dispatch failure or a missing dependency has not demonstrated that the
     * violation was rejected — it has demonstrated that nothing was tested.
     */
    public function test_every_negative_names_an_executed_violation(): void
    {
        foreach (self::examples() as $label => $row) {
            if (! str_contains($label, 'fails')) {
                continue;
            }
            $reason = $row[4];

            $this->assertNotSame(Reason::Satisfied, $reason, "{$label} is a negative that reports success");
            $this->assertFalse($reason->isDispatchFailure(), "{$label} is explained by the check never running");
            $this->assertFalse($reason->isDependencyMissing(), "{$label} is explained by a missing fixture, not a violation");
        }
    }

    /** Distinct types must not all collapse onto one catch-all reason. */
    public function test_negatives_are_distinguishable_from_one_another(): void
    {
        $reasons = [];
        foreach (self::examples() as $label => $row) {
            if (str_contains($label, 'fails')) {
                $reasons[$row[0]] = $row[4]->value;
            }
        }

        $this->assertGreaterThanOrEqual(
            15,
            count(array_unique($reasons)),
            'too many types share a reason code to tell their failures apart: '.json_encode($reasons),
        );
    }

    // ---------------------------------------------------------------- //
    //  The three ways an assertion never runs, each its own colour
    // ---------------------------------------------------------------- //

    public function test_an_unknown_type_is_an_error_not_a_violation(): void
    {
        $row = (new PropertyChecker)->check(['not_a_property:1'], self::draft())['results'][0];

        $this->assertSame(Reason::UnknownProperty->value, $row['reason']);
        $this->assertTrue(Reason::from($row['reason'])->isDispatchFailure());
    }

    public function test_a_planned_type_is_distinct_from_an_unknown_one(): void
    {
        $planned = PropertyRegistry::planned()[0];
        $row = (new PropertyChecker)->check([$planned], self::draft())['results'][0];

        $this->assertSame(Reason::PropertyNotImplemented->value, $row['reason']);
        $this->assertNotSame(Reason::UnknownProperty->value, $row['reason']);
    }

    public function test_a_malformed_argument_is_distinct_from_a_violation(): void
    {
        $row = (new PropertyChecker)->check(['steps_count:two'], self::draft())['results'][0];

        $this->assertSame(Reason::InvalidArgument->value, $row['reason']);
        $this->assertNotSame(Reason::StepCountMismatch->value, $row['reason']);
    }

    /**
     * The four distinctions a `max_length` negative has to survive, together.
     *
     * Each of these is red. Only the first is evidence that the length rule
     * works, and before reason codes all four were indistinguishable.
     */
    public function test_a_length_violation_is_distinguishable_from_the_ways_it_could_not_run(): void
    {
        $checker = new PropertyChecker;
        $long = ['type' => 'max_length', 'path' => 'steps.0.body', 'max' => 5];

        $this->assertSame(
            [
                Reason::MaxLengthExceeded->value,
                Reason::PathMissing->value,
                Reason::UnknownProperty->value,
                Reason::InvalidArgument->value,
            ],
            array_column($checker->check([
                $long,
                ['type' => 'has_key', 'key' => 'steps.0.nothing_here'],
                'max_lengthh:5',
                'steps_count:many',
            ], self::draft())['results'], 'reason'),
        );
    }

    /** `schema_valid` with no validator: nothing was tested, and the reason says so. */
    public function test_an_unavailable_validator_is_recorded_as_a_missing_dependency(): void
    {
        $result = (new PropertyChecker)->check(['schema_valid'], self::draft(), self::case(), null);
        $row = $result['results'][0];

        $this->assertSame('skipped', $row['status']);
        $this->assertSame(Reason::ValidatorUnavailable->value, $row['reason']);
        $this->assertTrue(Reason::from($row['reason'])->isDependencyMissing());
        $this->assertSame(0, $result['evaluated'], 'a skip must not count as evidence');
    }

    // ---------------------------------------------------------------- //
    //  no_banned_phrases: three sources, and they add rather than replace
    // ---------------------------------------------------------------- //

    /**
     * One negative per source of prohibition.
     *
     * The deleted singular read only `phrases`, which meant a case naming its
     * own list silently lost the other two. A single combined example could not
     * have caught that, because it cannot say which source fired.
     */
    public function test_each_source_of_prohibition_rejects_on_its_own(): void
    {
        $cases = [
            'property' => [['type' => 'no_banned_phrases', 'phrases' => ['kerfuffle']], 'A small kerfuffle about month-end.'],
            'brand' => ['no_banned_phrases', 'We can find real synergy here.'],
            'system' => ['no_banned_phrases', 'Just following up on my last note.'],
        ];

        foreach ($cases as $source => [$property, $body]) {
            $row = (new PropertyChecker)->check([$property], self::draft(body: $body), self::case())['results'][0];

            $this->assertSame('failed', $row['status'], "the {$source} rule did not reject");
            $this->assertSame(Reason::BannedPhrasePresent->value, $row['reason']);
            $this->assertNotSame([], array_filter($row['evidence'], fn (string $e): bool => str_starts_with($e, $source.':')),
                "the violation was not attributed to {$source}: ".json_encode($row['evidence']));
        }
    }

    /**
     * Explicit phrases *add to* the default and brand sets. They never replace
     * them — which is the exact behaviour the one-character-shorter name lost.
     */
    public function test_explicit_phrases_do_not_replace_the_brand_and_system_rules(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'no_banned_phrases', 'phrases' => ['kerfuffle']]],
            self::draft(body: 'A kerfuffle, real synergy, and just following up.'),
            self::case(),
        )['results'][0];

        $sources = array_map(fn (string $e): string => explode(':', $e)[0], $row['evidence']);

        $this->assertSame(['brand', 'property', 'system'], self::sorted(array_unique($sources)),
            'supplying explicit phrases must not disable the other two sources: '.json_encode($row['evidence']));
    }

    /**
     * Every offending item, not the first.
     *
     * One low-severity match must never be the whole explanation while a more
     * consequential one sits unreported in the same output.
     */
    public function test_every_offending_item_is_reported_not_only_the_first(): void
    {
        $row = (new PropertyChecker)->check(
            ['links_allowlisted'],
            self::draft(body: 'See https://evil.example/a and https://worse.example/b'),
            self::case(),
        )['results'][0];

        $this->assertCount(2, $row['evidence'], 'only the first offending link was reported');
    }

    /**
     * @return array<string, array{0: string, 1: string|array<string, mixed>, 2: mixed, 3: string, 4: Reason, 5?: ?string, 6?: ?array<string, mixed>}>
     */
    public static function examples(): array
    {
        $out = self::draft();
        $blocked = ['status' => 'blocked', 'missing' => [['path' => 'brand/voice.md', 'section' => 'Tone']]];
        $body = 'steps.0.body';

        return [
            // ------------------------------------------------------------ array form
            'has_key passes' => ['has_key', ['type' => 'has_key', 'key' => $body], $out, 'passed', Reason::Satisfied, $body],
            'has_key fails' => ['has_key', ['type' => 'has_key', 'key' => 'subject'], $out, 'failed', Reason::PathMissing, 'subject'],

            'count passes' => ['count', ['type' => 'count', 'path' => 'steps', 'equals' => 1], $out, 'passed', Reason::Satisfied, 'steps'],
            'count fails' => ['count', ['type' => 'count', 'path' => 'steps', 'equals' => 2], $out, 'failed', Reason::CountMismatch, 'steps'],

            'enum passes' => ['enum', ['type' => 'enum', 'path' => 'classification', 'values' => ['hot', 'warm']], $out, 'passed', Reason::Satisfied, 'classification'],
            'enum fails' => ['enum', ['type' => 'enum', 'path' => 'classification', 'values' => ['cold']], $out, 'failed', Reason::EnumValueNotAllowed, 'classification'],

            'cites_fact passes' => ['cites_fact', ['type' => 'cites_fact', 'fact' => '14 demos'], $out, 'passed', Reason::Satisfied],
            'cites_fact fails' => ['cites_fact', ['type' => 'cites_fact', 'fact' => 'forty demos'], $out, 'failed', Reason::FactNotCited],

            'max_length passes' => ['max_length', ['type' => 'max_length', 'path' => $body, 'max' => 200], $out, 'passed', Reason::Satisfied, $body],
            'max_length fails' => ['max_length', ['type' => 'max_length', 'path' => $body, 'max' => 5], $out, 'failed', Reason::MaxLengthExceeded, $body],

            'min_length passes' => ['min_length', ['type' => 'min_length', 'path' => $body, 'min' => 5], $out, 'passed', Reason::Satisfied, $body],
            'min_length fails' => ['min_length', ['type' => 'min_length', 'path' => $body, 'min' => 500], $out, 'failed', Reason::MinLengthNotMet, $body],

            'contains passes' => ['contains', ['type' => 'contains', 'path' => $body, 'text' => 'Acme'], $out, 'passed', Reason::Satisfied, $body],
            'contains fails' => ['contains', ['type' => 'contains', 'path' => $body, 'text' => 'Zebra'], $out, 'failed', Reason::TextNotFound, $body],

            'matches passes' => ['matches', ['type' => 'matches', 'path' => 'classification', 'pattern' => '/^h/'], $out, 'passed', Reason::Satisfied, 'classification'],
            'matches fails' => ['matches', ['type' => 'matches', 'path' => 'classification', 'pattern' => '/^z/'], $out, 'failed', Reason::PatternNotMatched, 'classification'],

            // ----------------------------------------------------------- string form
            'valid_json passes' => ['valid_json', 'valid_json', $out, 'passed', Reason::Satisfied],
            'valid_json fails' => ['valid_json', 'valid_json', 'not a JSON object at all', 'failed', Reason::NotJsonObject],

            'schema_valid passes' => ['schema_valid', 'schema_valid', $out, 'passed', Reason::Satisfied, null, ['ok' => true, 'errors' => []]],
            'schema_valid fails' => ['schema_valid', 'schema_valid', $out, 'failed', Reason::SchemaInvalid, null, ['ok' => false, 'errors' => ['schema $.angle: missing']]],

            'steps_count passes' => ['steps_count', 'steps_count:1', $out, 'passed', Reason::Satisfied, 'steps'],
            'steps_count fails' => ['steps_count', 'steps_count:2', $out, 'failed', Reason::StepCountMismatch, 'steps'],

            'beats_in_order passes' => ['beats_in_order', 'beats_in_order:problem', $out, 'passed', Reason::Satisfied, 'steps'],
            'beats_in_order fails' => ['beats_in_order', 'beats_in_order:proof', $out, 'failed', Reason::BeatOrderMismatch, 'steps'],

            'subjects_per_step passes' => ['subjects_per_step', 'subjects_per_step:2', $out, 'passed', Reason::Satisfied, 'steps'],
            'subjects_per_step fails' => ['subjects_per_step', 'subjects_per_step:3', $out, 'failed', Reason::SubjectCountMismatch, 'steps'],

            'single_cta_per_step passes' => ['single_cta_per_step', 'single_cta_per_step', $out, 'passed', Reason::Satisfied, 'steps'],
            'single_cta_per_step fails' => ['single_cta_per_step', 'single_cta_per_step', self::draft(cta: ''), 'failed', Reason::CtaMissing, 'steps.0.cta'],

            'no_unverified_figures passes' => ['no_unverified_figures', 'no_unverified_figures', $out, 'passed', Reason::Satisfied],
            'no_unverified_figures fails' => ['no_unverified_figures', 'no_unverified_figures', self::draft(body: 'We save you R2,500 every month.'), 'failed', Reason::FigureNotVerified],

            'links_allowlisted passes' => ['links_allowlisted', 'links_allowlisted', $out, 'passed', Reason::Satisfied],
            'links_allowlisted fails' => ['links_allowlisted', 'links_allowlisted', self::draft(body: 'Book at https://evil.example/y'), 'failed', Reason::LinkNotAllowlisted],

            'no_banned_phrases passes' => ['no_banned_phrases', 'no_banned_phrases', $out, 'passed', Reason::Satisfied],
            'no_banned_phrases fails' => ['no_banned_phrases', 'no_banned_phrases', self::draft(body: 'Just following up on my last note.'), 'failed', Reason::BannedPhrasePresent],

            'mentions_proof_point passes' => ['mentions_proof_point', 'mentions_proof_point', $out, 'passed', Reason::Satisfied],
            'mentions_proof_point fails' => ['mentions_proof_point', 'mentions_proof_point', self::draft(body: 'Hello there, do you have ten minutes free?'), 'failed', Reason::ProofPointMissing],

            'respects_never_say passes' => ['respects_never_say', 'respects_never_say', $out, 'passed', Reason::Satisfied],
            'respects_never_say fails' => ['respects_never_say', 'respects_never_say', self::draft(body: 'I can offer you a discount this month.'), 'failed', Reason::NeverSayViolated],

            'personalisation_slot_present passes' => ['personalisation_slot_present', 'personalisation_slot_present:1', $out, 'passed', Reason::Satisfied],
            'personalisation_slot_present fails' => ['personalisation_slot_present', 'personalisation_slot_present:2', $out, 'failed', Reason::PersonalisationSlotsInsufficient],

            'blocked_missing_brain passes' => ['blocked_missing_brain', 'blocked_missing_brain:brand/voice.md#Tone', $blocked, 'passed', Reason::Satisfied, 'missing'],
            'blocked_missing_brain fails' => ['blocked_missing_brain', 'blocked_missing_brain:brand/voice.md#Tone', $out, 'failed', Reason::NotBlockedOnRef, 'missing'],
        ];
    }

    /** @param array<int|string, string> $values */
    private static function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values);

        return $values;
    }

    /** One fixture brain behind every example, so a failure is about the property and not the setup. */
    private static function case(): EvalCase
    {
        return EvalCase::fromArray([
            'id' => 'property_checker_fixture',
            'fixture_brain' => [
                'offer/offer.md' => <<<'MD'
                    ---
                    links:
                      - https://cal.example/x
                    proof_points:
                      - "Acme booked 14 demos in 30 days"
                    ---
                    ## Products and services
                    Monthly bookkeeping from R1,800 per month.

                    ## Proof
                    Acme booked 14 demos in 30 days.
                    MD,
                'brand/voice.md' => "## Do and don't\nDon't say \"synergy\".\n",
                'people/user.md' => "## Never say or offer\nNever offer a discount.\n",
            ],
        ]);
    }

    /**
     * The passing output. Each failing example damages exactly one thing, which
     * is what makes the failure attributable to the property under test.
     */
    private static function draft(?string $body = null, ?string $cta = null): array
    {
        return [
            'classification' => 'hot',
            'steps' => [[
                'step' => 1,
                'beat' => 'problem',
                'subjects' => ['Your month-end', 'Kitchen-table bookkeeping'],
                'body' => $body ?? 'Acme booked 14 demos in 30 days on R1,800 per month. See https://cal.example/x',
                'cta' => $cta ?? 'Worth a look',
                'personalisation_slot' => 'their recent funding round',
            ]],
        ];
    }
}
