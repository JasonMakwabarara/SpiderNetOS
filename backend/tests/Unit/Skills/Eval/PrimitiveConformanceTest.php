<?php

declare(strict_types=1);

namespace Tests\Unit\Skills\Eval;

use App\Services\Skills\Eval\Cardinality;
use App\Services\Skills\Eval\PathMatches;
use App\Services\Skills\Eval\PathOutcome;
use App\Services\Skills\Eval\PropertyChecker;
use App\Services\Skills\Eval\PropertyRegistry;
use App\Services\Skills\Eval\Reason;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * One contract, checked the same way for every primitive.
 *
 * A happy-path example per primitive is not enough. Eight handlers each reading
 * an array, a fan-out and a missing key for themselves would grow eight
 * slightly different opinions about what "nothing there" means, and the
 * disagreements would only show up in a corpus nobody has authored yet.
 *
 * So the primitives do not resolve anything. They receive subjects from one
 * boundary that has already applied `PropertyPath` and the declared
 * cardinality, and every case below is a shape that boundary has to get right
 * once on behalf of all of them.
 */
class PrimitiveConformanceTest extends TestCase
{
    /** The eight the 43 domain names collapse onto. */
    private const PRIMITIVES = [
        'field', 'is_null', 'not_contains', 'not_matches', 'not_empty',
        'set_equals', 'set_includes', 'set_excludes',
    ];

    /**
     * No primitive resolves a path or counts matches for itself.
     *
     * A structural check rather than a behavioural one, because the failure it
     * prevents is a future handler quietly calling `dig()` and reintroducing
     * the collapse the resolver exists to remove.
     */
    public function test_no_primitive_addresses_the_output_on_its_own(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(PropertyChecker::class))->getFileName());

        foreach (self::PRIMITIVES as $name) {
            $handler = PropertyRegistry::get($name)?->handler;
            $this->assertNotNull($handler, "{$name} has no handler");

            $body = self::bodyOf($source, (string) $handler);
            $this->assertMatchesRegularExpression(
                '/\$this->(overSubjects|overSet)\(/',
                $body,
                "{$handler}() does not go through the resolving boundary",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/self::dig\(|PropertyPath::resolve\(\$c->output/',
                $body,
                "{$handler}() addresses the output directly instead of using the subjects it is given",
            );
        }
    }

    // ---------------------------------------------------------------- //
    //  Zero / one / many, and the shapes that used to collapse
    // ---------------------------------------------------------------- //

    /** @return array<string, array{0: mixed, 1: array<string, mixed>, 2: string, 3: Reason}> */
    public static function shapes(): array
    {
        $notContains = ['type' => 'not_contains', 'path' => 'items[].body', 'text' => 'banned'];

        return [
            'missing path' => [['other' => 1], $notContains, 'failed', Reason::PathMissing],
            'explicit null' => [['items' => null], $notContains, 'failed', Reason::TypeMismatch],
            'empty collection' => [['items' => []], $notContains, 'failed', Reason::PathEmptyCollection],
            'scalar where a collection was needed' => [['items' => 'nope'], $notContains, 'failed', Reason::TypeMismatch],

            'one subject, satisfied' => [self::bodies(['fine']), $notContains, 'passed', Reason::Satisfied],
            'one subject, violated' => [self::bodies(['banned']), $notContains, 'failed', Reason::TextPresent],

            'many subjects, all satisfied' => [self::bodies(['a', 'b', 'c']), $notContains, 'passed', Reason::Satisfied],
            'first subject violates' => [self::bodies(['banned', 'b', 'c']), $notContains, 'failed', Reason::TextPresent],
            'middle subject violates' => [self::bodies(['a', 'banned', 'c']), $notContains, 'failed', Reason::TextPresent],
            'last subject violates' => [self::bodies(['a', 'b', 'banned']), $notContains, 'failed', Reason::TextPresent],
        ];
    }

    /** @param array<string, mixed> $property */
    #[DataProvider('shapes')]
    public function test_the_boundary_reads_every_shape_the_same_way(mixed $output, array $property, string $status, Reason $reason): void
    {
        $row = (new PropertyChecker)->check([$property], $output)['results'][0];

        $this->assertSame($status, $row['status'], $row['detail']);
        $this->assertSame($reason->value, $row['reason'], $row['detail']);
    }

    // ---------------------------------------------------------------- //
    //  Presence and value are different questions
    // ---------------------------------------------------------------- //

    /**
     * `false`, `0` and `""` are values a model produced, not absence.
     *
     * The `isset()` defect collapsed present-and-null into missing, and PHP's
     * `empty()` would collapse `false` and `0` into empty. Both would make a
     * primitive report that a field was never there when the model said `0`.
     *
     * @return array<string, array{0: mixed, 1: string, 2: Reason}>
     */
    public static function falsey(): array
    {
        return [
            'false is a value' => [false, 'passed', Reason::Satisfied],
            'zero is a value' => [0, 'passed', Reason::Satisfied],
            'zero string is a value' => ['0', 'passed', Reason::Satisfied],
            'empty string is empty' => ['', 'failed', Reason::ValueEmpty],
            'whitespace is empty' => ['   ', 'failed', Reason::ValueEmpty],
            'null is empty' => [null, 'failed', Reason::ValueEmpty],
            'empty array is empty' => [[], 'failed', Reason::ValueEmpty],
        ];
    }

    #[DataProvider('falsey')]
    public function test_a_falsey_value_is_present_not_missing(mixed $value, string $status, Reason $reason): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'not_empty', 'path' => 'fit_score']],
            ['fit_score' => $value],
        )['results'][0];

        $this->assertSame($status, $row['status'], $row['detail']);
        $this->assertSame($reason->value, $row['reason'], $row['detail']);
    }

    /**
     * `is_null` must pass on exactly the state it asserts, however the case
     * chooses to address it.
     *
     * This failed before the boundary stopped refusing `NullAtPath`: a path
     * naming the nullable field directly was rejected as unresolvable, so the
     * one primitive whose entire job is recognising null could not see one.
     * Found by the conformance suite rather than by an example.
     */
    public function test_is_null_passes_whether_the_null_is_reached_directly_or_through_a_selector(): void
    {
        $checker = new PropertyChecker;

        $direct = $checker->check([['type' => 'is_null', 'path' => 'items.0.draft_reply']], ['items' => [['draft_reply' => null]]])['results'][0];
        $this->assertSame('passed', $direct['status'], 'a directly addressed null: '.$direct['detail']);

        $selected = $checker->check(
            [['type' => 'is_null', 'path' => 'items', 'where' => ['message_id' => 'm4'], 'field' => 'draft_reply']],
            ['items' => [['message_id' => 'm4', 'draft_reply' => null]]],
        )['results'][0];
        $this->assertSame('passed', $selected['status'], 'a selected null: '.$selected['detail']);

        // And absence still is not null, by either route.
        $absent = $checker->check([['type' => 'is_null', 'path' => 'items', 'where' => ['message_id' => 'm4'], 'field' => 'draft_reply']], ['items' => [['message_id' => 'm4']]])['results'][0];
        $this->assertSame('failed', $absent['status']);
        $this->assertStringContainsString('absent', $absent['detail']);
    }

    /** And absence is its own answer, distinct from every value above. */
    public function test_an_absent_field_is_not_an_empty_one(): void
    {
        $row = (new PropertyChecker)->check([['type' => 'not_empty', 'path' => 'fit_score']], ['other' => 1])['results'][0];

        $this->assertSame(Reason::PathMissing->value, $row['reason']);
        $this->assertNotSame(Reason::ValueEmpty->value, $row['reason']);
    }

    // ---------------------------------------------------------------- //
    //  Objects reach the primitives through the same door
    // ---------------------------------------------------------------- //

    public function test_an_object_subject_is_read_through_the_resolver(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'not_empty', 'path' => 'reply']],
            (object) ['reply' => 'something'],
        )['results'][0];

        $this->assertSame('passed', $row['status'], $row['detail']);
    }

    public function test_an_object_property_holding_null_is_present_and_empty_not_missing(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'not_empty', 'path' => 'reply']],
            (object) ['reply' => null],
        )['results'][0];

        // `isset()` would have called this missing. Present-and-null reaches the
        // primitive as a subject whose value is null, so the verdict is about
        // the value — and it is a different verdict from "the path is not there".
        $this->assertSame(Reason::ValueEmpty->value, $row['reason'], $row['detail']);
        $this->assertNotSame(Reason::PathMissing->value, $row['reason']);
    }

    // ---------------------------------------------------------------- //
    //  Evidence: all of it, attributed, and in a stable order
    // ---------------------------------------------------------------- //

    public function test_every_offending_subject_is_reported_with_its_path(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'not_contains', 'path' => 'items[].body', 'text' => 'banned']],
            self::bodies(['banned', 'fine', 'banned again']),
        )['results'][0];

        $this->assertCount(2, $row['evidence'], 'only some of the offenders were reported');
        $this->assertStringStartsWith('items.0.body:', $row['evidence'][0]);
        $this->assertStringStartsWith('items.2.body:', $row['evidence'][1]);
    }

    public function test_evidence_order_is_deterministic(): void
    {
        $output = self::bodies(['banned', 'x', 'banned', 'y', 'banned']);
        $property = ['type' => 'not_contains', 'path' => 'items[].body', 'text' => 'banned'];
        $checker = new PropertyChecker;

        $this->assertSame(
            $checker->check([$property], $output)['results'][0]['evidence'],
            $checker->check([$property], $output)['results'][0]['evidence'],
        );
    }

    /** A set assertion's evidence is sorted, so the same set always reads the same. */
    public function test_set_evidence_does_not_depend_on_document_order(): void
    {
        $property = ['type' => 'set_equals', 'path' => 'items[].role', 'values' => ['story', 'tip']];
        $checker = new PropertyChecker;

        $first = $checker->check([$property], ['items' => [['role' => 'cta'], ['role' => 'story']]])['results'][0];
        $second = $checker->check([$property], ['items' => [['role' => 'story'], ['role' => 'cta']]])['results'][0];

        $this->assertSame($first['evidence'], $second['evidence']);
    }

    // ---------------------------------------------------------------- //
    //  Cardinality is the registry's to decide, not the handler's
    // ---------------------------------------------------------------- //

    public function test_a_failure_names_the_cardinality_that_refused_it(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'field', 'path' => 'items', 'where' => ['id' => 'nobody'], 'field' => 'x', 'equals' => 'y']],
            ['items' => [['id' => 'a'], ['id' => 'b']]],
        )['results'][0];

        $this->assertSame(Reason::SelectorMatchedNone->value, $row['reason']);
    }

    /** `field` is exactly-one, so two matching items is an output failure, not a loop. */
    public function test_exactly_one_refuses_a_selector_that_matches_twice(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'field', 'path' => 'items', 'where' => ['id' => 'a'], 'field' => 'x', 'equals' => 'y']],
            ['items' => [['id' => 'a', 'x' => 'y'], ['id' => 'a', 'x' => 'y']]],
        )['results'][0];

        $this->assertSame('failed', $row['status'], 'two subjects satisfied an assertion about one');
        $this->assertSame(Reason::SelectorMatchedMany->value, $row['reason']);
        $this->assertStringContainsString(Cardinality::ExactlyOne->value, $row['detail']);
    }

    /**
     * The safe derivation: a Root default addressing many subjects becomes
     * non-empty, so an empty collection cannot pass by having nothing to break.
     */
    public function test_a_root_primitive_over_a_fan_out_still_needs_a_subject(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'not_contains', 'path' => 'items[].body', 'text' => 'banned']],
            ['items' => []],
        )['results'][0];

        $this->assertSame('failed', $row['status'], 'an empty collection satisfied a "contains nothing" assertion');
    }

    // ---------------------------------------------------------------- //
    //  Subject counts, asserted rather than inferred
    //
    //  The boundary answers three questions and no others: was the path
    //  resolved, how many subjects, what was each value. Until `subjects`
    //  was carried on the result, "one subject reached the handler" could
    //  only be inferred from a reason code that happens to have a single
    //  producer - a property no test asserted and any second call site
    //  would have broken silently.
    // ---------------------------------------------------------------- //

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: int, 3: Reason}> */
    public static function boundaryCounts(): array
    {
        return [
            'missing path' => [['elsewhere' => 1], 'fit_score', 0, Reason::PathMissing],
            'present with null' => [['fit_score' => null], 'fit_score', 1, Reason::ValueEmpty],
            'present with ""' => [['fit_score' => ''], 'fit_score', 1, Reason::ValueEmpty],
            'present with []' => [['fit_score' => []], 'fit_score', 1, Reason::ValueEmpty],
            'present with false' => [['fit_score' => false], 'fit_score', 1, Reason::Satisfied],
            'present with 0' => [['fit_score' => 0], 'fit_score', 1, Reason::Satisfied],
            'present with "0"' => [['fit_score' => '0'], 'fit_score', 1, Reason::Satisfied],
            'fan-out, empty parent' => [['items' => []], 'items[].body', 0, Reason::PathEmptyCollection],
            'fan-out, three incl null' => [
                ['items' => [['body' => 'a'], ['body' => null], ['body' => 'c']]],
                'items[].body', 3, Reason::ValueEmpty,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    #[DataProvider('boundaryCounts')]
    public function test_the_boundary_reports_how_many_subjects_it_resolved(array $output, string $path, int $subjects, Reason $reason): void
    {
        $row = (new PropertyChecker)->check([['type' => 'not_empty', 'path' => $path]], $output)['results'][0];

        $this->assertSame($subjects, $row['subjects'], $row['detail']);
        $this->assertSame($reason->value, $row['reason'], $row['detail']);
    }

    /**
     * The same output, two spellings, two different subject counts - and the
     * same member count. This is the separation in one assertion: path
     * cardinality is not set size.
     */
    public function test_path_cardinality_and_set_size_are_different_numbers(): void
    {
        $output = ['tags' => ['a', 'b']];
        $spec = static fn (string $p): array => [['type' => 'set_equals', 'path' => $p, 'values' => ['a', 'b']]];

        $whole = (new PropertyChecker)->check($spec('tags'), $output)['results'][0];
        $fanned = (new PropertyChecker)->check($spec('tags[]'), $output)['results'][0];

        $this->assertSame(1, $whole['subjects'], 'the collection itself is ONE subject');
        $this->assertSame(2, $fanned['subjects'], 'fanning out over it is TWO subjects');
        $this->assertStringContainsString('2 member(s)', $whole['detail']);
        $this->assertStringContainsString('2 member(s)', $fanned['detail']);
    }

    /**
     * `Root` means the path addresses one site and permits no selector, so zero
     * sites is not a state it can be satisfied by. It cannot arrive through
     * PropertyChecker today, because refuse() answers an unresolved path first
     * and every zero-yielding outcome escalates Root to EveryMatchNonEmpty -
     * but that is an invariant of two other functions, and a rule that depends
     * on somebody else's escalation is one refactor from letting zero pass.
     */
    public function test_root_cardinality_is_not_satisfied_by_zero_subjects(): void
    {
        $nothing = PathMatches::none(PathOutcome::EmptyCollection, 'items[]', 'empty collection');

        $this->assertSame(Reason::NoSubjectsToEvaluate, Cardinality::Root->reject($nothing));
        $this->assertNull(
            Cardinality::Root->reject(PathMatches::matched([['path' => 'items', 'value' => 'x']])),
            'one subject still satisfies Root',
        );
    }

    // ---------------------------------------------------------------- //
    //  An empty expectation is not an assertion
    // ---------------------------------------------------------------- //

    /**
     * `set_equals` with no values says "this collection is empty", which is a
     * real claim. "Includes nothing" and "excludes nothing" are true of every
     * output ever produced, so they claim nothing - and a property that cannot
     * fail is what this stage exists to remove.
     *
     * @return array<string, array{0: string, 1: string, 2: Reason}>
     */
    public static function emptyExpectations(): array
    {
        return [
            'set_equals accepts it' => ['set_equals', 'passed', Reason::Satisfied],
            'set_includes refuses it' => ['set_includes', 'failed', Reason::InvalidArgument],
            'set_excludes refuses it' => ['set_excludes', 'failed', Reason::InvalidArgument],
        ];
    }

    #[DataProvider('emptyExpectations')]
    public function test_an_empty_expected_set_has_a_declared_behaviour(string $type, string $status, Reason $reason): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => $type, 'path' => 'tags', 'values' => []]],
            ['tags' => []],
        )['results'][0];

        $this->assertSame($status, $row['status'], $row['detail']);
        $this->assertSame($reason->value, $row['reason'], $row['detail']);
    }

    // ---------------------------------------------------------------- //
    //  The set operand contract
    //
    //  `path: tags` resolves ONE subject whose value is the collection;
    //  `path: tags[]` resolves one subject per member. Both address the same
    //  set. Before this was declared, the first spelling stringified the whole
    //  array into a single fictitious member - `["a","b"]` became the one
    //  member `'["a","b"]'`, and `[]` became the member `'[]'`.
    // ---------------------------------------------------------------- //

    public function test_both_spellings_of_a_set_address_the_same_members(): void
    {
        $spec = static fn (string $path): array => [
            ['type' => 'set_equals', 'path' => $path, 'values' => ['a', 'b']],
        ];
        $output = ['tags' => ['a', 'b']];

        $whole = (new PropertyChecker)->check($spec('tags'), $output)['results'][0];
        $fanned = (new PropertyChecker)->check($spec('tags[]'), $output)['results'][0];

        $this->assertSame('passed', $whole['status'], $whole['detail']);
        $this->assertSame('passed', $fanned['status'], $fanned['detail']);

        // Count evidence, not just colour: the collection must be read as two
        // members, never as one string that happens to contain both.
        $this->assertStringContainsString('2 member(s)', $whole['detail']);
        $this->assertStringContainsString('2 member(s)', $fanned['detail']);
    }

    public function test_a_nested_member_is_a_type_mismatch_not_a_stringified_member(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'set_equals', 'path' => 'tags[]', 'values' => ['a']]],
            ['tags' => [['deep' => 'a']]],
        )['results'][0];

        $this->assertSame('failed', $row['status'], $row['detail']);
        $this->assertSame(Reason::TypeMismatch->value, $row['reason'], $row['detail']);
    }

    // ---------------------------------------------------------------- //
    //  Path cardinality is not set size
    // ---------------------------------------------------------------- //

    /**
     * `path: blocks` over `{"blocks": []}` resolves EXACTLY ONE subject whose
     * value is `[]`. Cardinality is satisfied and cannot help here, so the set
     * primitive has to refuse on its own - which is what its registry entry
     * has always claimed: "no block has role cta" over zero blocks establishes
     * nothing. It used to pass, because `[]` became the member `'[]'` and that
     * string is not in the forbidden list.
     */
    public function test_set_excludes_refuses_an_empty_set_at_a_resolved_path(): void
    {
        foreach (['blocks', 'blocks[]'] as $path) {
            $row = (new PropertyChecker)->check(
                [['type' => 'set_excludes', 'path' => $path, 'values' => ['cta']]],
                ['blocks' => []],
            )['results'][0];

            $this->assertSame('failed', $row['status'], $path.': '.$row['detail']);
            $this->assertNotSame(
                Reason::Satisfied->value,
                $row['reason'],
                $path.' let "excludes cta" pass over zero members',
            );
        }
    }

    /** An empty actual set genuinely equals an empty expected set. */
    public function test_an_empty_set_equals_an_empty_expected_set(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'set_equals', 'path' => 'tags', 'values' => []]],
            ['tags' => []],
        )['results'][0];

        $this->assertSame('passed', $row['status'], $row['detail']);
        $this->assertStringContainsString('0 member(s)', $row['detail']);
    }

    // ---------------------------------------------------------------- //
    //  Comparison preserves type
    // ---------------------------------------------------------------- //

    /**
     * Four members that text comparison collapsed into two. `scalarText(null)`
     * and `scalarText('')` are both `''`, and `asSet` then dropped every `''`
     * - so a null member was deleted before the sets were compared.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function distinctTypes(): array
    {
        return [
            'null is not the empty string' => [null, ''],
            'false is not the string false' => [false, 'false'],
            'zero is not the string zero' => [0, '0'],
            'true is not the string true' => [true, 'true'],
        ];
    }

    #[DataProvider('distinctTypes')]
    public function test_a_set_keeps_two_types_apart(mixed $actual, mixed $expected): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'set_equals', 'path' => 'tags', 'values' => [$expected]]],
            ['tags' => [$actual]],
        )['results'][0];

        $this->assertSame('failed', $row['status'], $row['detail']);
        $this->assertSame(Reason::SetMismatch->value, $row['reason'], $row['detail']);
    }

    #[DataProvider('distinctTypes')]
    public function test_field_equality_keeps_two_types_apart(mixed $actual, mixed $expected): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'field', 'path' => 'fit', 'field' => 'score', 'equals' => $expected]],
            ['fit' => ['score' => $actual]],
        )['results'][0];

        $this->assertSame('failed', $row['status'], $row['detail']);
        $this->assertSame(Reason::FieldValueMismatch->value, $row['reason'], $row['detail']);
    }

    /** A null member is a member. It used to be silently removed. */
    public function test_a_null_member_is_not_deleted_from_the_set(): void
    {
        $row = (new PropertyChecker)->check(
            [['type' => 'set_equals', 'path' => 'tags', 'values' => ['a']]],
            ['tags' => ['a', null]],
        )['results'][0];

        $this->assertSame('failed', $row['status'], $row['detail']);
        $this->assertStringContainsString('<null>', $row['detail'], 'the null member vanished from the comparison');
    }

    /** @param list<string> $bodies */
    private static function bodies(array $bodies): array
    {
        return ['items' => array_map(static fn (string $b): array => ['body' => $b], $bodies)];
    }

    /**
     * The source of one method, for the structural check.
     *
     * The slice ends at the next class-body member, not at the next backticked
     * marker. `checkSetExcludes` is the last handler carrying one, so the old
     * bound ran its slice to end of file - roughly 380 lines of unrelated
     * helpers. That satisfied its allow-pattern from any overSet() call down
     * there, and would have blamed it for a dig() added anywhere below it. The
     * eighth primitive was effectively unchecked.
     */
    private static function bodyOf(string $source, string $method): string
    {
        $at = strpos($source, "function {$method}(");
        if ($at === false) {
            return '';
        }
        $rest = substr($source, $at);
        $end = preg_match('/\n    (?:\/\*\*|private |public |protected |\})/', $rest, $m, PREG_OFFSET_CAPTURE, 1) === 1
            ? $m[0][1]
            : strlen($rest);

        return substr($rest, 0, $end);
    }
}
