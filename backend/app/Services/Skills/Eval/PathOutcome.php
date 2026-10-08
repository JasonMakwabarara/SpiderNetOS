<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * What resolving a path against an output actually found.
 *
 * Seven cases, because the alternative is one empty array standing for five
 * different facts. `PropertyChecker::dig()` returns `null` with a single
 * `?bool $exists`, and `PropertyContext::for()` discards even that — it calls
 * `dig($output, $path)` with no third argument — so every path-scoped handler
 * receives `target === null` for a missing field, an explicit null, and a
 * traversal that walked into a string alike.
 *
 * These are live distinctions in this corpus, not hypotheticals. In the
 * inbox-triage schema `draft_reply` is `["string","null"]` and is *not*
 * required, so `draft_reply_null:m4` has three different output shapes and only
 * one of them is the violation. `slots` is optional with `maxItems: 2` and no
 * `minItems`, so an absent `slots` and `slots: []` are different facts that both
 * count zero.
 *
 * The resolver reports; it never assigns blame. Whether a `TypeMismatch` is a
 * case-authoring error or a malformed output depends on the card's schema, which
 * the resolver does not read — if the schema requires `slots[].time` and the
 * model emitted strings, the assertion is correct and the output is wrong.
 */
enum PathOutcome: string
{
    /** At least one concrete path resolved to a value. */
    case Matched = 'MATCHED';

    /** A segment does not exist on its parent. */
    case MissingField = 'MISSING_FIELD';

    /** The path resolved and the value is an explicit null. */
    case NullAtPath = 'NULL_AT_PATH';

    /** The path resolved to an empty collection, before any selector applied. */
    case EmptyCollection = 'EMPTY_COLLECTION';

    /** The collection is non-empty and `where:` matched nothing in it. */
    case NoSelectorMatch = 'NO_SELECTOR_MATCH';

    /** Traversal reached a scalar where it needed a collection or an object. */
    case TypeMismatch = 'TYPE_MISMATCH';

    /** The member exists but cannot be read — a private property, or no accessor. */
    case Inaccessible = 'INACCESSIBLE';

    /** Anything that is not a match. Kept as a method so call sites cannot drift. */
    public function isMatch(): bool
    {
        return $this === self::Matched;
    }

    /**
     * Whether the path itself could not be walked, as opposed to walking fine
     * and finding nothing selected.
     *
     * `EmptyCollection` and `NoSelectorMatch` are legitimate states of a
     * well-formed output; the rest mean the address did not fit the document.
     */
    public function isAddressingFailure(): bool
    {
        return in_array($this, [self::MissingField, self::TypeMismatch, self::Inaccessible], true);
    }
}
