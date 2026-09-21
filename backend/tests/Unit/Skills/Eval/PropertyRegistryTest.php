<?php

declare(strict_types=1);

namespace Tests\Unit\Skills\Eval;

use App\Services\Skills\Eval\PropertyChecker;
use App\Services\Skills\Eval\PropertyRegistry;
use App\Services\Skills\Eval\PropertyType;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The registry proves itself in both directions, or it is just a list.
 *
 * Making the registry the dispatch table removes exactly one failure mode — a
 * second table disagreeing with the declaration. Everything below is one of the
 * failure modes it does not remove: a handler that does not exist, a name the
 * corpus uses and nothing declares, an entry nothing dispatches to, an argument
 * shape that does not match what the cases actually write, and debt with no
 * owner.
 */
class PropertyRegistryTest extends TestCase
{
    // ---------------------------------------------------------------- //
    //  Nothing declared points at something missing
    // ---------------------------------------------------------------- //

    public function test_every_implemented_entry_has_a_handler_method(): void
    {
        $checker = new PropertyChecker;

        foreach (PropertyRegistry::all() as $name => $type) {
            if (! $type->isImplemented()) {
                continue;
            }

            $this->assertTrue(
                method_exists($checker, (string) $type->handler),
                "{$name} declares handler {$type->handler}, which PropertyChecker does not have",
            );
        }
    }

    // ---------------------------------------------------------------- //
    //  Nothing undeclared exists
    // ---------------------------------------------------------------- //

    public function test_every_handler_method_is_registered(): void
    {
        $declared = [];
        foreach (PropertyRegistry::all() as $type) {
            if ($type->handler !== null) {
                $declared[$type->handler] = true;
            }
        }

        foreach ((new ReflectionClass(PropertyChecker::class))->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {
            if (! str_starts_with($method->getName(), 'check')) {
                continue;
            }

            $this->assertArrayHasKey(
                $method->getName(),
                $declared,
                "PropertyChecker::{$method->getName()}() exists but no registry entry dispatches to it — "
                    .'an arm that can never run is the drift the registry is supposed to make impossible',
            );
        }
    }

    public function test_every_property_type_the_corpus_uses_is_registered(): void
    {
        $unregistered = [];
        foreach (self::corpusProperties() as [$slug, $caseId, $name, $arg]) {
            if (! PropertyRegistry::has($name)) {
                $unregistered[$name] ??= "{$slug}/{$caseId}";
            }
        }

        $this->assertSame([], $unregistered, 'property types named by a case and declared nowhere');
    }

    /**
     * What PropertyArg was written for, wired to nothing until now.
     *
     * This is what makes `slots_count:two` a validate error rather than a
     * confusing eval failure after a model has already been called.
     */
    public function test_every_corpus_argument_satisfies_its_declared_shape(): void
    {
        $rejected = [];
        foreach (self::corpusProperties() as [$slug, $caseId, $name, $arg]) {
            $type = PropertyRegistry::get($name);
            if ($type === null) {
                continue;
            }
            if (($why = $type->arg->reject($arg, $name)) !== null) {
                $rejected[] = "{$slug}/{$caseId}: {$why}";
            }
        }

        $this->assertSame([], $rejected);
    }

    // ---------------------------------------------------------------- //
    //  Declared debt is owned, and cannot pass
    // ---------------------------------------------------------------- //

    public function test_every_planned_entry_is_owned_dated_and_has_a_migration_target(): void
    {
        foreach (PropertyRegistry::all() as $name => $type) {
            if ($type->isImplemented()) {
                continue;
            }

            $this->assertNotNull($type->owner, "{$name} is planned with no owner");
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $type->reviewDate, "{$name} has no review date");
            $this->assertNotNull($type->collapsesInto, "{$name} does not say what it collapses into");
            $this->assertNotSame('', $type->summary, "{$name} does not say what it asserts");
        }
    }

    public function test_a_planned_type_cannot_pass_and_says_why(): void
    {
        $planned = PropertyRegistry::planned();
        $this->assertNotSame([], $planned, 'this test is vacuous once everything is implemented — delete it then');

        $result = (new PropertyChecker)->check([$planned[0]], ['anything' => true]);

        $this->assertSame('failed', $result['results'][0]['status']);
        $this->assertStringContainsString('no handler yet', $result['results'][0]['detail']);
    }

    public function test_an_unregistered_type_is_a_different_failure_from_a_planned_one(): void
    {
        $this->assertStringContainsString('unknown property type', (string) PropertyRegistry::reject('not_a_property', null));
        $this->assertStringContainsString('no handler yet', (string) PropertyRegistry::reject(PropertyRegistry::planned()[0], null));
        $this->assertNull(PropertyRegistry::reject('valid_json', null));
    }

    // ---------------------------------------------------------------- //
    //  The migration inventory is a partition, not two overlapping lists
    // ---------------------------------------------------------------- //

    public function test_the_migration_inventory_partitions_cleanly(): void
    {
        $planned = array_filter(PropertyRegistry::all(), fn (PropertyType $t): bool => ! $t->isImplemented());

        $domain = array_keys(array_filter($planned, fn (PropertyType $t): bool => $t->isDomain()));
        $collapse = array_keys(array_filter($planned, fn (PropertyType $t): bool => ! $t->isDomain()));

        $this->assertSame([], array_intersect($domain, $collapse), 'a name in both groups');
        $this->assertCount(
            count($planned),
            array_merge($domain, $collapse),
            'every planned type is either collapsed or domain, and never both',
        );

        // An earlier draft had subject_length_max in both lists and was
        // internally inconsistent about the baseline. These numbers are the
        // computed partition, and they are asserted so the next edit has to
        // update them deliberately.
        $this->assertCount(43, $collapse);
        $this->assertCount(8, $domain);
        $this->assertCount(21, PropertyRegistry::implemented());
        $this->assertCount(72, PropertyRegistry::names());
    }

    /**
     * Eight implemented primitives are used by no case — the exact hole a
     * dispatcher cannot see, because nothing ever asks it for them.
     *
     * Being unused is allowed. Being unused *by accident* is not. Six are the
     * targets 43 names collapse onto, and the other two say in the registry why
     * they are kept. Anything else is dead code, and this test is how it stays
     * visible instead of accumulating.
     *
     * It has already earned its keep: it found `no_banned_phrase`, which was
     * neither — a redundant near-duplicate of `no_banned_phrases` differing by
     * one character, where the shorter name silently dropped the brand-voice and
     * default bans from a safety check.
     */
    public function test_every_unused_primitive_is_a_collapse_target_or_says_why_it_is_kept(): void
    {
        $used = array_unique(array_column(iterator_to_array(self::corpusProperties()), 2));
        $unused = array_values(array_diff(PropertyRegistry::implemented(), $used));

        $targets = [];
        foreach (PropertyRegistry::all() as $type) {
            if (! $type->isImplemented() && ! $type->isDomain()) {
                $targets[(string) $type->collapsesInto] = true;
            }
        }

        foreach ($unused as $name) {
            $type = PropertyRegistry::get($name);

            $this->assertTrue(
                isset($targets[$name]) || $type?->retained !== null,
                "{$name} is implemented, used by no case, and is neither a collapse target nor marked "
                    .'retained with a reason — which is what dead code looks like before anyone notices',
            );
        }
    }

    /**
     * A retention rationale is a declaration. On its own it is the original
     * problem in miniature.
     *
     * `retained` explains why an implemented primitive that no case uses is
     * kept. It does not establish that the primitive works, and an unused,
     * untested handler is indistinguishable from a broken one — which is how
     * `no_banned_phrase` survived. So the rule is all three: unused, with a
     * stated reason, **and** exercised in both directions by name.
     */
    public function test_a_retained_primitive_is_exercised_in_both_directions(): void
    {
        $directions = [];
        foreach (PropertyCheckerTest::examples() as $label => $row) {
            $directions[$row[0]][] = str_contains($label, 'fails') ? 'failed' : 'passed';
        }

        $retained = [];
        foreach (PropertyRegistry::all() as $name => $type) {
            if ($type->isImplemented() && $type->retained !== null) {
                $retained[] = $name;
            }
        }
        $this->assertNotSame([], $retained, 'this test is vacuous with nothing retained — delete it then');

        foreach ($retained as $name) {
            $this->assertContains('passed', $directions[$name] ?? [], "{$name} is retained with a reason but has no passing example");
            $this->assertContains('failed', $directions[$name] ?? [], "{$name} is retained with a reason but has no failing example");
        }
    }

    /**
     * @return \Generator<array{0: string, 1: string, 2: string, 3: ?string}>
     */
    private static function corpusProperties(): \Generator
    {
        $root = (string) config('agents.skills_root');

        foreach (glob($root.'/*/evals/cases.yaml') ?: [] as $file) {
            $slug = basename(dirname($file, 2));
            foreach ((array) (Yaml::parseFile($file)['cases'] ?? []) as $case) {
                foreach ((array) ($case['expect']['properties'] ?? []) as $property) {
                    $spec = PropertyChecker::normalise($property);
                    yield [
                        $slug,
                        (string) ($case['id'] ?? '?'),
                        (string) $spec['type'],
                        isset($spec['arg']) && is_string($spec['arg']) ? $spec['arg'] : null,
                    ];
                }
            }
        }
    }
}
