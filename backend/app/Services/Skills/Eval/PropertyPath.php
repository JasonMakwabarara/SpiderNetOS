<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

use ReflectionObject;

/**
 * Resolves a path against a skill output and reports what it found.
 *
 * Three responsibilities are kept apart, because collapsing any two is how a
 * green result conceals missing evidence:
 *
 *   skills:validate  — is the case's path and selector well formed
 *   PropertyPath     — what exists, what matches, and where
 *   the handler      — do those findings satisfy the assertion
 *
 * This class is the middle one. It never judges. A selector matching nothing is
 * a fact, not a verdict: "every required reply has a source" and "no prohibited
 * reply exists" must not treat an empty selection the same way, and only the
 * assertion knows which it is.
 *
 * The selector is a parameter, never syntax. `where:` is a sibling key of
 * `path` in the case mapping, so it is passed in and cannot be smuggled through
 * a path string — which is the whole reason the structured mapping form was
 * chosen over a compact mini-language that would have grown a parser.
 */
final class PropertyPath
{
    /** A segment: a name, optionally with `[]` to fan out over its collection. */
    private const SEGMENT = '/^(?<name>[^.\[\]=]+)(?<fanout>\[\])?$/';

    /**
     * Resolve `$path` against `$output`, optionally filtering the collection it
     * addresses by `$where`.
     *
     * @param  array<string, mixed>  $where  field => expected value, applied to the collection at `$path`
     * @param  array<string, mixed>  $context  the case's declared inputs, for selector identity
     */
    public static function resolve(mixed $output, string $path, array $where = [], array $context = []): PathMatches
    {
        if (($why = self::grammarError($path)) !== null) {
            return PathMatches::none(PathOutcome::TypeMismatch, $path, $why);
        }

        $frontier = [['path' => '', 'value' => $output]];
        $malformed = [];

        foreach (self::segments($path) as $segment) {
            [$next, $stops] = self::descend($frontier, $segment['name'], $segment['fanout']);
            $malformed = array_merge($malformed, $stops);

            if ($next === []) {
                // Nothing survived this segment. With a single site the reason
                // is unambiguous; with fan-out the first stop is representative
                // and the rest travel in `malformed`.
                $first = $stops[0] ?? ['path' => $path, 'outcome' => PathOutcome::MissingField, 'found' => 'nothing'];

                return PathMatches::none($first['outcome'], $first['path'], $first['found'], $malformed);
            }
            $frontier = $next;
        }

        if ($where !== []) {
            return self::filter($frontier, $where, $malformed);
        }

        // An explicit null is only *the* answer when the address is singular.
        // Under fan-out a null is one value among several, and collapsing the
        // set would throw away the count that keeps an "every item" assertion
        // honest.
        if (count($frontier) === 1 && $frontier[0]['value'] === null) {
            return PathMatches::none(PathOutcome::NullAtPath, $frontier[0]['path'], 'null', $malformed);
        }

        return PathMatches::matched($frontier, $malformed);
    }

    /**
     * The grammar, stated positively: dot-separated names, numeric indexes, and
     * `[]` to expand a collection. Nothing else.
     *
     * The rule is **no predicates**, not "no brackets" — an earlier draft
     * outlawed `[` outright while the corpus's own examples wrote
     * `items[].subject`, so the example was invalid under its own validator.
     * Filtering is `where:`, which is a separate key, so `items[status=open]`
     * is rejected as an embedded predicate and `items[0]` is rejected because
     * the index form is `items.0`. One spelling per concept.
     */
    public static function grammarError(string $path): ?string
    {
        if (trim($path) === '') {
            return 'path is empty';
        }
        if (str_contains($path, '=')) {
            return "path \"{$path}\" contains \"=\" — filtering belongs in `where:`, not in the path";
        }

        foreach (explode('.', $path) as $segment) {
            if ($segment === '') {
                return "path \"{$path}\" has an empty segment";
            }
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                if (preg_match('/^[^\[\]]+\[[^\]]+\]$/', $segment) === 1) {
                    return "path \"{$path}\": use `items.0` for an index and `where:` for a filter, not \"{$segment}\"";
                }

                return "path \"{$path}\": segment \"{$segment}\" is not a name, a name with `[]`, or an index";
            }
        }

        return null;
    }

    /** @return list<array{name: string, fanout: bool}> */
    private static function segments(string $path): array
    {
        $out = [];
        foreach (explode('.', $path) as $segment) {
            preg_match(self::SEGMENT, $segment, $m);
            $out[] = ['name' => $m['name'], 'fanout' => ($m['fanout'] ?? '') === '[]'];
        }

        return $out;
    }

    /**
     * One segment, across every branch of the frontier.
     *
     * @param  list<array{path: string, value: mixed}>  $frontier
     * @return array{0: list<array{path: string, value: mixed}>, 1: list<array{path: string, outcome: PathOutcome, found: string}>}
     */
    private static function descend(array $frontier, string $name, bool $fanout): array
    {
        $next = [];
        $stops = [];

        foreach ($frontier as $node) {
            $here = self::join($node['path'], $name);
            [$found, $value, $outcome] = self::member($node['value'], $name);

            if (! $found) {
                $stops[] = ['path' => $here, 'outcome' => $outcome, 'found' => self::describe($node['value'])];

                continue;
            }

            if (! $fanout) {
                $next[] = ['path' => $here, 'value' => $value];

                continue;
            }

            if (! is_array($value)) {
                $stops[] = ['path' => $here.'[]', 'outcome' => PathOutcome::TypeMismatch, 'found' => self::describe($value)];

                continue;
            }
            if ($value === []) {
                $stops[] = ['path' => $here.'[]', 'outcome' => PathOutcome::EmptyCollection, 'found' => 'empty collection'];

                continue;
            }
            foreach ($value as $key => $item) {
                $next[] = ['path' => self::join($here, (string) $key), 'value' => $item];
            }
        }

        return [$next, $stops];
    }

    /**
     * Read one member, testing existence per container type.
     *
     * `isset()` is never used: it is false for a member that exists and holds
     * null, which is exactly the distinction this class exists to preserve.
     * `dig()` gets the array case right and the object case wrong — latent only
     * because both `json_decode` sites pass `true`.
     *
     * @return array{0: bool, 1: mixed, 2: PathOutcome}
     */
    private static function member(mixed $container, string $name): array
    {
        if (is_array($container)) {
            return array_key_exists($name, $container)
                ? [true, $container[$name], PathOutcome::Matched]
                : [false, null, PathOutcome::MissingField];
        }

        if (is_object($container)) {
            if (property_exists($container, $name)) {
                $property = (new ReflectionObject($container))->getProperty($name);

                return $property->isPublic()
                    ? [true, $property->getValue($container), PathOutcome::Matched]
                    : [false, null, PathOutcome::Inaccessible];
            }
            // A DTO's value may not be a property at all. An explicit accessor
            // step, rather than pretending the object is an array.
            if (method_exists($container, $name)) {
                return [true, $container->{$name}(), PathOutcome::Matched];
            }
            if ($container instanceof \ArrayAccess && $container->offsetExists($name)) {
                return [true, $container->offsetGet($name), PathOutcome::Matched];
            }

            return [false, null, PathOutcome::MissingField];
        }

        // A scalar or null where a container was needed. The resolver reports
        // this; whether the case or the output is at fault is decided against
        // the card's schema, which this class does not read.
        return [false, null, PathOutcome::TypeMismatch];
    }

    /**
     * Apply `where:` to the collection each branch resolved to.
     *
     * @param  list<array{path: string, value: mixed}>  $frontier
     * @param  array<string, mixed>  $where
     * @param  list<array{path: string, outcome: PathOutcome, found: string}>  $malformed
     */
    private static function filter(array $frontier, array $where, array $malformed): PathMatches
    {
        $matches = [];
        $sawCollection = false;
        $sawItem = false;

        foreach ($frontier as $node) {
            if (! is_array($node['value'])) {
                $malformed[] = ['path' => $node['path'], 'outcome' => PathOutcome::TypeMismatch, 'found' => self::describe($node['value'])];

                continue;
            }
            $sawCollection = true;

            foreach ($node['value'] as $key => $item) {
                $sawItem = true;
                if (self::itemMatches($item, $where)) {
                    $matches[] = ['path' => self::join($node['path'], (string) $key), 'value' => $item];
                }
            }
        }

        if ($matches !== []) {
            return PathMatches::matched($matches, $malformed);
        }
        if (! $sawCollection) {
            return PathMatches::none(PathOutcome::TypeMismatch, $frontier[0]['path'] ?? null, 'not a collection', $malformed);
        }
        if (! $sawItem) {
            return PathMatches::none(PathOutcome::EmptyCollection, $frontier[0]['path'] ?? null, 'empty collection', $malformed);
        }

        return PathMatches::none(PathOutcome::NoSelectorMatch, $frontier[0]['path'] ?? null, self::describeWhere($where), $malformed);
    }

    /**
     * Selector fields are identifiers — `message_id: m1`, `thread_id: t4` — so
     * they are compared as strings. A YAML scalar arrives as an int or a string
     * depending on how it was written, and `"1" !== 1` would make an identity
     * check depend on quoting. A non-scalar on either side never matches.
     *
     * @param  array<string, mixed>  $where
     */
    private static function itemMatches(mixed $item, array $where): bool
    {
        foreach ($where as $field => $expected) {
            [$found, $value] = self::member($item, (string) $field);
            if (! $found || ! is_scalar($value) || ! is_scalar($expected)) {
                return false;
            }
            if ((string) $value !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    private static function join(string $prefix, string $segment): string
    {
        return $prefix === '' ? $segment : $prefix.'.'.$segment;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => 'array('.count($value).')',
            is_object($value) => $value::class,
            is_string($value) => 'string("'.mb_substr($value, 0, 24).'")',
            default => get_debug_type($value),
        };
    }

    /** @param array<string, mixed> $where */
    private static function describeWhere(array $where): string
    {
        $parts = [];
        foreach ($where as $field => $expected) {
            $parts[] = $field.'='.(is_scalar($expected) ? (string) $expected : get_debug_type($expected));
        }

        return implode(', ', $parts);
    }
}
