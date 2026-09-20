<?php

declare(strict_types=1);

namespace Tests\Unit\Skills\Eval;

use App\Services\Skills\Eval\EvalCase;
use App\Services\Skills\Eval\PropertyChecker;
use App\Services\Skills\Eval\PropertyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every implemented property type, passing and failing.
 *
 * This is the safety net for turning PropertyChecker's switch into a registry
 * dispatch table. The transposition is only safe if something already pins the
 * behaviour of all 22 arms, and until now 15 of them were exercised in one
 * Feature test that also asserted the whole real corpus scores nothing.
 *
 * A passing example on its own proves very little: a handler that returns
 * `passed` unconditionally satisfies it. Each type therefore carries a failing
 * example built by damaging the passing one — the wrong count, the off-allowlist
 * link, the figure that is not in the brain — so a handler that never rejects
 * anything is visible.
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
        ?array $validator = null,
    ): void {
        $result = (new PropertyChecker)->check([$property], $output, self::case(), $validator);
        $row = $result['results'][0];

        $this->assertSame(
            $expected,
            $row['status'],
            "{$type}: expected {$expected}, got {$row['status']} — {$row['detail']}",
        );
        $this->assertSame($type, $row['type']);
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

    /** `schema_valid` with no validator is the one legitimate skip in the checker today. */
    public function test_an_unavailable_validator_skips_rather_than_passing(): void
    {
        $result = (new PropertyChecker)->check(['schema_valid'], self::draft(), self::case(), null);

        $this->assertSame('skipped', $result['results'][0]['status']);
        $this->assertSame(0, $result['evaluated'], 'a skip must not count as evidence');
        $this->assertTrue($result['passed'], 'a skip is not a failure either — which is the bug the gate has to catch upstream');
    }

    /**
     * @return array<string, array{0: string, 1: string|array<string, mixed>, 2: mixed, 3: string, 4?: array<string, mixed>}>
     */
    public static function examples(): array
    {
        $out = self::draft();
        $blocked = ['status' => 'blocked', 'missing' => [['path' => 'brand/voice.md', 'section' => 'Tone']]];

        return [
            // ------------------------------------------------------------ array form
            'has_key passes' => ['has_key', ['type' => 'has_key', 'key' => 'steps.0.body'], $out, 'passed'],
            'has_key fails' => ['has_key', ['type' => 'has_key', 'key' => 'subject'], $out, 'failed'],

            'count passes' => ['count', ['type' => 'count', 'path' => 'steps', 'equals' => 1], $out, 'passed'],
            'count fails' => ['count', ['type' => 'count', 'path' => 'steps', 'equals' => 2], $out, 'failed'],

            'enum passes' => ['enum', ['type' => 'enum', 'path' => 'classification', 'values' => ['hot', 'warm']], $out, 'passed'],
            'enum fails' => ['enum', ['type' => 'enum', 'path' => 'classification', 'values' => ['cold']], $out, 'failed'],

            'cites_fact passes' => ['cites_fact', ['type' => 'cites_fact', 'fact' => '14 demos'], $out, 'passed'],
            'cites_fact fails' => ['cites_fact', ['type' => 'cites_fact', 'fact' => 'forty demos'], $out, 'failed'],

            'max_length passes' => ['max_length', ['type' => 'max_length', 'path' => 'steps.0.body', 'max' => 200], $out, 'passed'],
            'max_length fails' => ['max_length', ['type' => 'max_length', 'path' => 'steps.0.body', 'max' => 5], $out, 'failed'],

            'min_length passes' => ['min_length', ['type' => 'min_length', 'path' => 'steps.0.body', 'min' => 5], $out, 'passed'],
            'min_length fails' => ['min_length', ['type' => 'min_length', 'path' => 'steps.0.body', 'min' => 500], $out, 'failed'],

            'contains passes' => ['contains', ['type' => 'contains', 'path' => 'steps.0.body', 'text' => 'Acme'], $out, 'passed'],
            'contains fails' => ['contains', ['type' => 'contains', 'path' => 'steps.0.body', 'text' => 'Zebra'], $out, 'failed'],

            'matches passes' => ['matches', ['type' => 'matches', 'path' => 'classification', 'pattern' => '/^h/'], $out, 'passed'],
            'matches fails' => ['matches', ['type' => 'matches', 'path' => 'classification', 'pattern' => '/^z/'], $out, 'failed'],

            // ----------------------------------------------------------- string form
            'valid_json passes' => ['valid_json', 'valid_json', $out, 'passed'],
            'valid_json fails' => ['valid_json', 'valid_json', 'not a JSON object at all', 'failed'],

            'schema_valid passes' => ['schema_valid', 'schema_valid', $out, 'passed', ['ok' => true, 'errors' => []]],
            'schema_valid fails' => ['schema_valid', 'schema_valid', $out, 'failed', ['ok' => false, 'errors' => ['schema $.angle: missing']]],

            'steps_count passes' => ['steps_count', 'steps_count:1', $out, 'passed'],
            'steps_count fails' => ['steps_count', 'steps_count:2', $out, 'failed'],

            'beats_in_order passes' => ['beats_in_order', 'beats_in_order:problem', $out, 'passed'],
            'beats_in_order fails' => ['beats_in_order', 'beats_in_order:proof', $out, 'failed'],

            'subjects_per_step passes' => ['subjects_per_step', 'subjects_per_step:2', $out, 'passed'],
            'subjects_per_step fails' => ['subjects_per_step', 'subjects_per_step:3', $out, 'failed'],

            'single_cta_per_step passes' => ['single_cta_per_step', 'single_cta_per_step', $out, 'passed'],
            'single_cta_per_step fails' => ['single_cta_per_step', 'single_cta_per_step', self::draft(cta: ''), 'failed'],

            'no_unverified_figures passes' => ['no_unverified_figures', 'no_unverified_figures', $out, 'passed'],
            'no_unverified_figures fails' => ['no_unverified_figures', 'no_unverified_figures', self::draft(body: 'We save you R2,500 every month.'), 'failed'],

            'links_allowlisted passes' => ['links_allowlisted', 'links_allowlisted', $out, 'passed'],
            'links_allowlisted fails' => ['links_allowlisted', 'links_allowlisted', self::draft(body: 'Book at https://evil.example/y'), 'failed'],

            'no_banned_phrases passes' => ['no_banned_phrases', 'no_banned_phrases', $out, 'passed'],
            'no_banned_phrases fails' => ['no_banned_phrases', 'no_banned_phrases', self::draft(body: 'Just following up on my last note.'), 'failed'],

            'mentions_proof_point passes' => ['mentions_proof_point', 'mentions_proof_point', $out, 'passed'],
            'mentions_proof_point fails' => ['mentions_proof_point', 'mentions_proof_point', self::draft(body: 'Hello there, do you have ten minutes free?'), 'failed'],

            'respects_never_say passes' => ['respects_never_say', 'respects_never_say', $out, 'passed'],
            'respects_never_say fails' => ['respects_never_say', 'respects_never_say', self::draft(body: 'I can offer you a discount this month.'), 'failed'],

            'personalisation_slot_present passes' => ['personalisation_slot_present', 'personalisation_slot_present:1', $out, 'passed'],
            'personalisation_slot_present fails' => ['personalisation_slot_present', 'personalisation_slot_present:2', $out, 'failed'],

            'blocked_missing_brain passes' => ['blocked_missing_brain', 'blocked_missing_brain:brand/voice.md#Tone', $blocked, 'passed'],
            'blocked_missing_brain fails' => ['blocked_missing_brain', 'blocked_missing_brain:brand/voice.md#Tone', $out, 'failed'],
        ];
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
