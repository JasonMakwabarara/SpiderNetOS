<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * What a property does when its path or selector resolves to nothing.
 *
 * This is the vacuous-truth decision, made once per type instead of implicitly
 * per handler. It is the same bug as `executed = 0, failed = 0 -> EXIT 0` at the
 * scale of a single assertion: "every angle has a source" is trivially satisfied
 * by a report with no angles, and a suite full of those certifies nothing.
 *
 * The corpus already contains the live instance. `every_angle_has_source` is
 * asserted in three prospect-research cases; only one of them pairs it with
 * `angles_min:1`. The other two — `no_sources_no_angles` and
 * `never_invent_funding_or_hires` — would be satisfied by an empty `angles[]`,
 * which is the opposite of what their judge notes describe.
 *
 * The resolver reports the match count; this decides what the count means.
 */
enum Cardinality: string
{
    /**
     * The path addresses a single site and no selector is permitted. Emptiness
     * is not a concept: the value is there, absent, or null, and PathOutcome
     * already says which.
     */
    case Root = 'root';

    /** The selector must resolve to exactly one item. Zero and two both fail. */
    case ExactlyOne = 'exactly_one';

    /** At least one match is required; the assertion then applies to all of them. */
    case AtLeastOne = 'at_least_one';

    /** Applies to every match, and zero matches is a failure. */
    case EveryMatchNonEmpty = 'every_match_non_empty';

    /**
     * Applies to every match, and zero matches passes.
     *
     * Deliberately the longest name, because this is the one that lets a
     * vacuous assertion through and choosing it should feel like a decision. An
     * entry declaring it **must** name the collection a sibling count assertion
     * has to address (`pairs_with`), and `skills:validate` then requires that
     * count to exist in the same case with a bound that either excludes zero or
     * asserts exactly zero.
     *
     * A registry field alone would not be enough: it can say a companion ought
     * to exist, but not that it exists *in this case*, addresses *the same
     * collection*, or actually excludes zero. A count over `sources` proves
     * nothing about `angles`.
     */
    case EveryMatchMayBeEmpty = 'every_match_may_be_empty';

    /** Whether zero matches is permitted by this rule alone. */
    public function admitsEmpty(): bool
    {
        return $this === self::EveryMatchMayBeEmpty || $this === self::Root;
    }

    /** Whether a sibling count assertion must be present in the same case. */
    public function needsPairedCount(): bool
    {
        return $this === self::EveryMatchMayBeEmpty;
    }

    /** How this rule reads a resolved match set. Null means the rule is satisfied. */
    public function reject(PathMatches $matches): ?Reason
    {
        return match ($this) {
            self::Root => null,
            self::ExactlyOne => match (true) {
                $matches->count() === 1 => null,
                $matches->count() === 0 => Reason::SelectorMatchedNone,
                default => Reason::SelectorMatchedMany,
            },
            self::AtLeastOne, self::EveryMatchNonEmpty => $matches->count() >= 1 ? null : Reason::SelectorMatchedNone,
            self::EveryMatchMayBeEmpty => null,
        };
    }
}
