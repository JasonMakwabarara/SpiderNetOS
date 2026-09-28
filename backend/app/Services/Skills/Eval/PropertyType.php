<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * One row of the PropertyRegistry, hydrated.
 *
 * A readonly object rather than the raw array so call sites get type safety
 * without the catalogue giving up the `private const` idiom the codebase uses
 * for fixed vocabularies (ConnectorRegistry).
 */
final readonly class PropertyType
{
    public function __construct(
        public string $name,
        public PropertyArg $arg,
        public string $status,
        /** Method on PropertyChecker, or null while the type is planned. */
        public ?string $handler,
        public string $summary,
        /**
         * What this property does when nothing resolves. The vacuous-truth
         * decision, made per type instead of implicitly per handler.
         */
        public Cardinality $cardinality = Cardinality::Root,
        /**
         * The collection a sibling count assertion must address, for a type
         * that tolerates an empty match set. Required when the cardinality is
         * EveryMatchMayBeEmpty, meaningless otherwise.
         */
        public ?string $pairsWith = null,
        /**
         * The collection this property counts, when it is a count assertion.
         * This is what lets the pairing check match a companion to its subject
         * by target rather than by name.
         */
        public ?string $counts = null,
        /**
         * How a count assertion's argument reads: `min`, `equals` or `range`.
         * Without it the pairing check cannot tell `angles_min:0`, which admits
         * an empty collection without asserting one, from `items_count:0`,
         * which deliberately asserts it.
         */
        public ?string $bound = null,
        /** Why an implemented type that no case uses is kept. Null unless it is one. */
        public ?string $retained = null,
        /** The primitive this collapses onto, or `domain` when it keeps its own name. */
        public ?string $collapsesInto = null,
        /** Owned debt: who, and when it is looked at again. Null once implemented. */
        public ?string $owner = null,
        public ?string $reviewDate = null,
    ) {}

    public function isImplemented(): bool
    {
        return $this->status === PropertyRegistry::IMPLEMENTED && $this->handler !== null;
    }

    /** A planned type that keeps its own name rather than folding into a primitive. */
    public function isDomain(): bool
    {
        return $this->collapsesInto === 'domain';
    }
}
