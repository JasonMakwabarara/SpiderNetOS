<?php

declare(strict_types=1);

namespace Tests\Unit\Skills\Eval;

use App\Services\Skills\Eval\PathMatches;
use App\Services\Skills\Eval\PathOutcome;
use App\Services\Skills\Eval\PropertyPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The five collapses this resolver exists to prevent, one fixture each.
 *
 * `PropertyChecker::dig()` returns `null` with a single `?bool $exists`, and
 * `PropertyContext::for()` discards even that, so a missing field, an explicit
 * null and a traversal into a string all arrive at a handler as `null`. Every
 * assertion below is a pair of shapes that were indistinguishable before.
 *
 * The shapes are the corpus's own, not invented ones. In the inbox-triage
 * schema `draft_reply` is `["string","null"]` and is not required, so
 * `draft_reply_null:m4` has three different output shapes and only one of them
 * is the violation. `slots` is optional with no `minItems`, so absent and `[]`
 * are different facts that both count zero.
 */
class PropertyPathTest extends TestCase
{
    // ---------------------------------------------------------------- //
    //  The matrix: every row distinguishable from every other row
    // ---------------------------------------------------------------- //

    /** @return array<string, array{0: mixed, 1: string, 2: array<string, mixed>, 3: PathOutcome}> */
    public static function matrix(): array
    {
        $items = ['items' => [
            ['message_id' => 'm1', 'draft_reply' => 'Sure, Thursday works.', 'slots' => ['09:00', '11:00']],
            ['message_id' => 'm4', 'draft_reply' => null],
            ['message_id' => 'm7', 'slots' => []],
        ]];

        return [
            // the collection itself
            'missing collection' => [['items' => []], 'inbound', [], PathOutcome::MissingField],
            'explicit null collection' => [['items' => null], 'items[]', [], PathOutcome::TypeMismatch],
            'empty collection' => [['items' => []], 'items[]', [], PathOutcome::EmptyCollection],
            'one entry' => [['items' => [['message_id' => 'm1']]], 'items[]', [], PathOutcome::Matched],
            'several entries' => [$items, 'items[]', [], PathOutcome::Matched],

            // a scalar where a collection was required
            'scalar instead of collection' => [['slots' => '09:00'], 'slots[]', [], PathOutcome::TypeMismatch],
            'traversal into a string' => [['slots' => ['09:00']], 'slots.0.time', [], PathOutcome::TypeMismatch],

            // the three shapes of draft_reply, which the schema makes legal
            'field present with a value' => [$items, 'items.0.draft_reply', [], PathOutcome::Matched],
            'field present and null' => [$items, 'items.1.draft_reply', [], PathOutcome::NullAtPath],
            'field absent entirely' => [$items, 'items.2.draft_reply', [], PathOutcome::MissingField],

            // absent slots vs empty slots — both count zero, different facts
            'slots absent' => [$items, 'items.1.slots[]', [], PathOutcome::MissingField],
            'slots empty' => [$items, 'items.2.slots[]', [], PathOutcome::EmptyCollection],

            // the selector
            'selector matches one' => [$items, 'items', ['message_id' => 'm1'], PathOutcome::Matched],
            'selector matches none' => [$items, 'items', ['message_id' => 'm9'], PathOutcome::NoSelectorMatch],
            'selector on an empty collection' => [['items' => []], 'items', ['message_id' => 'm1'], PathOutcome::EmptyCollection],
            'selector on a non-collection' => [['items' => 'nope'], 'items', ['message_id' => 'm1'], PathOutcome::TypeMismatch],
        ];
    }

    /** @param array<string, mixed> $where */
    #[DataProvider('matrix')]
    public function test_each_shape_is_distinguishable(mixed $output, string $path, array $where, PathOutcome $expected): void
    {
        $this->assertSame($expected, PropertyPath::resolve($output, $path, $where)->outcome);
    }

    /** The point of the matrix: no two rows may share a result the handler acts on. */
    public function test_the_matrix_actually_separates_the_collapsed_cases(): void
    {
        $shapes = [
            'missing' => ['items.2.draft_reply', []],
            'null' => ['items.1.draft_reply', []],
            'empty' => ['items.2.slots[]', []],
            'unmatched' => ['items', ['message_id' => 'm9']],
            'mistyped' => ['items.0.draft_reply.nested', []],
        ];
        [$output] = [self::matrix()['several entries'][0]];

        $outcomes = [];
        foreach ($shapes as $label => [$path, $where]) {
            $outcomes[$label] = PropertyPath::resolve($output, $path, $where)->outcome->value;
        }

        $this->assertSame(
            count($shapes),
            count(array_unique($outcomes)),
            'two shapes that must stay apart resolved to the same outcome: '.json_encode($outcomes),
        );
    }

    // ---------------------------------------------------------------- //
    //  The count is load-bearing
    // ---------------------------------------------------------------- //

    public function test_the_count_survives_fan_out(): void
    {
        $out = ['items' => [['a' => 1], ['a' => 2], ['a' => 3]]];
        $m = PropertyPath::resolve($out, 'items[].a');

        $this->assertSame(3, $m->count());
        $this->assertSame([1, 2, 3], $m->values());
        $this->assertSame(['items.0.a', 'items.1.a', 'items.2.a'], $m->paths());
    }

    /**
     * Under fan-out a null is one value among several, not "the answer".
     * Collapsing the set to `NullAtPath` would discard the count that keeps an
     * "every item" assertion honest.
     */
    public function test_a_null_under_fan_out_stays_a_value(): void
    {
        $m = PropertyPath::resolve(['items' => [['r' => null], ['r' => 'x'], ['r' => null]]], 'items[].r');

        $this->assertSame(PathOutcome::Matched, $m->outcome);
        $this->assertSame(3, $m->count());
        $this->assertSame([null, 'x', null], $m->values());
    }

    public function test_a_lone_null_is_the_answer_not_a_match(): void
    {
        $m = PropertyPath::resolve(['draft_reply' => null], 'draft_reply');

        $this->assertSame(PathOutcome::NullAtPath, $m->outcome);
        $this->assertSame(0, $m->count());
        $this->assertFalse($m->isMatch());
    }

    // ---------------------------------------------------------------- //
    //  Partial collections: neither "no matches" nor "all matches"
    // ---------------------------------------------------------------- //

    public function test_a_partly_malformed_collection_reports_which_entries_failed(): void
    {
        $out = ['items' => [
            ['slots' => ['09:00']],
            ['slots' => 'not a list'],
            ['slots' => ['11:00']],
        ]];
        $m = PropertyPath::resolve($out, 'items[].slots[]');

        $this->assertTrue($m->isMatch(), 'the well-formed entries still resolved');
        $this->assertTrue($m->isPartial(), 'a partly broken collection must not look tidy');
        $this->assertSame(2, $m->count());
        $this->assertSame('items.1.slots[]', $m->malformed[0]['path'], 'the offending entry is named');
        $this->assertSame(PathOutcome::TypeMismatch, $m->malformed[0]['outcome']);
    }

    // ---------------------------------------------------------------- //
    //  Object containers: isset() is never the existence test
    // ---------------------------------------------------------------- //

    public function test_a_public_property_holding_null_is_present_not_missing(): void
    {
        $m = PropertyPath::resolve(new PathFixtureObject, 'reply');

        $this->assertSame(PathOutcome::NullAtPath, $m->outcome, 'isset() would have called this missing');
    }

    public function test_a_private_property_is_inaccessible_not_missing(): void
    {
        $this->assertSame(PathOutcome::Inaccessible, PropertyPath::resolve(new PathFixtureObject, 'secret')->outcome);
    }

    public function test_a_value_exposed_through_a_method_is_reachable(): void
    {
        $m = PropertyPath::resolve(new PathFixtureObject, 'computed');

        $this->assertSame(PathOutcome::Matched, $m->outcome);
        $this->assertSame('from-accessor', $m->sole());
    }

    // ---------------------------------------------------------------- //
    //  Grammar: no predicates, rather than no brackets
    // ---------------------------------------------------------------- //

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function grammar(): array
    {
        return [
            'plain path' => ['steps.0.body', null],
            'fan-out' => ['items[].subject', null],
            'fan-out then field' => ['blocks[].role', null],
            'embedded predicate' => ['items[status=open]', '='],
            'bracketed index' => ['items[0]', 'use `items.0`'],
            'equals anywhere' => ['classification=hot', '='],
            'empty segment' => ['items..subject', 'empty segment'],
            'empty path' => ['', 'empty'],
        ];
    }

    #[DataProvider('grammar')]
    public function test_the_path_grammar_admits_fan_out_and_refuses_predicates(string $path, ?string $expect): void
    {
        $error = PropertyPath::grammarError($path);

        if ($expect === null) {
            $this->assertNull($error, "{$path} should be a legal path");

            return;
        }
        $this->assertNotNull($error, "{$path} should have been rejected");
        $this->assertStringContainsString($expect, $error);
    }

    /** The corpus's own example must be legal under the corpus's own validator. */
    public function test_the_spelling_used_by_the_plan_is_legal(): void
    {
        foreach (['items[].subject', 'sections', 'steps.0.body', 'angles[].source'] as $path) {
            $this->assertNull(PropertyPath::grammarError($path), "{$path} is written in the plan and must parse");
        }
    }

    // ---------------------------------------------------------------- //
    //  Evidence stays bounded without becoming misleading
    // ---------------------------------------------------------------- //

    public function test_evidence_is_capped_but_the_total_is_true(): void
    {
        $out = ['items' => array_map(static fn (int $i): array => ['a' => $i], range(1, 47))];
        $evidence = PropertyPath::resolve($out, 'items[].a')->evidence(cap: 5);

        $this->assertSame(47, $evidence['total'], '"3" and "3 of 47" are different statements');
        $this->assertCount(5, $evidence['examples']);
    }

    public function test_the_sample_is_deterministic(): void
    {
        $out = ['items' => array_map(static fn (int $i): array => ['a' => $i], range(1, 20))];

        $this->assertSame(
            PropertyPath::resolve($out, 'items[].a')->evidence(),
            PropertyPath::resolve($out, 'items[].a')->evidence(),
        );
    }

    /** A selector naming a field the items do not carry matches nothing — it does not crash. */
    public function test_a_selector_on_an_unknown_field_matches_nothing(): void
    {
        $m = PropertyPath::resolve(['items' => [['message_id' => 'm1']]], 'items', ['thread_id' => 't1']);

        $this->assertSame(PathOutcome::NoSelectorMatch, $m->outcome);
        $this->assertStringContainsString('thread_id=t1', (string) $m->found);
    }

    /** Identifiers are compared as strings, so an unquoted YAML scalar still matches. */
    public function test_identifier_comparison_does_not_depend_on_yaml_quoting(): void
    {
        $out = ['items' => [['step' => 2, 'body' => 'x']]];

        $this->assertTrue(PropertyPath::resolve($out, 'items', ['step' => 2])->isMatch());
        $this->assertTrue(PropertyPath::resolve($out, 'items', ['step' => '2'])->isMatch());
    }

    /** A grammar failure never resolves — it cannot be mistaken for a document fact. */
    public function test_an_illegal_path_never_reports_a_match(): void
    {
        $m = PropertyPath::resolve(['items' => [['a' => 1]]], 'items[a=1]');

        $this->assertFalse($m->isMatch());
        $this->assertInstanceOf(PathMatches::class, $m);
    }
}

/** A container that is not an array, to pin the object existence rules. */
class PathFixtureObject
{
    public ?string $reply = null;

    private string $secret = 'not yours';

    public function computed(): string
    {
        return 'from-accessor';
    }
}
