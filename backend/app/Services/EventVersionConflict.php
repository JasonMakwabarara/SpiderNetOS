<?php

namespace App\Services;

/**
 * An append named the version it expected an aggregate to be at, and the
 * aggregate had moved on.
 *
 * Raised under the event-sequence lock, so `found` is the version that exists,
 * not a stale read. It is the caller's to act on — reload and decide again —
 * which the unique-index error a racing append used to surface as was not.
 * Still a RuntimeException, so existing handlers keep catching it.
 */
final class EventVersionConflict extends \RuntimeException
{
    public function __construct(
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly int $expected,
        public readonly int $found,
    ) {
        parent::__construct("Concurrency conflict: expected version {$expected}, found {$found}");
    }
}
