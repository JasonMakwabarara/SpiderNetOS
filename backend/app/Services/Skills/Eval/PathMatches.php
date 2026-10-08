<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * The result of resolving a path — never `null`, because `null` is a legitimate
 * value in this corpus and a resolver that signals absence with it cannot
 * express the one distinction it most needs to.
 *
 * The count is load-bearing rather than incidental: a selector matching nothing
 * must never silently satisfy an "every matching item" assertion. That is the
 * vacuous truth this stage exists to remove, one level below the run.
 *
 * `malformed` is what stops a partially broken collection from resolving to a
 * tidy lie. Three well-formed items and two that walked into a string is
 * neither "no matches" nor "all matches" — it is five facts, and the offending
 * concrete paths travel with the result so a handler can name them.
 */
final readonly class PathMatches
{
    /**
     * @param  list<array{path: string, value: mixed}>  $matches  concrete path => value, in document order
     * @param  list<array{path: string, outcome: PathOutcome, found: string}>  $malformed  branches that could not be walked
     */
    private function __construct(
        public PathOutcome $outcome,
        public array $matches,
        public ?string $stoppedAt,
        public ?string $found,
        public array $malformed,
    ) {}

    /**
     * @param  list<array{path: string, value: mixed}>  $matches
     * @param  list<array{path: string, outcome: PathOutcome, found: string}>  $malformed
     */
    public static function matched(array $matches, array $malformed = []): self
    {
        return new self(PathOutcome::Matched, $matches, null, null, $malformed);
    }

    /**
     * @param  list<array{path: string, outcome: PathOutcome, found: string}>  $malformed
     */
    public static function none(PathOutcome $outcome, ?string $stoppedAt = null, ?string $found = null, array $malformed = []): self
    {
        return new self($outcome, [], $stoppedAt, $found, $malformed);
    }

    /** The true number of matches, never a capped or sampled one. */
    public function count(): int
    {
        return count($this->matches);
    }

    public function isMatch(): bool
    {
        return $this->outcome->isMatch();
    }

    /** True when some branches resolved and others could not be walked. */
    public function isPartial(): bool
    {
        return $this->matches !== [] && $this->malformed !== [];
    }

    /** @return list<mixed> */
    public function values(): array
    {
        return array_map(static fn (array $m): mixed => $m['value'], $this->matches);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (array $m): string => $m['path'], $this->matches);
    }

    /** The single matched value, or null when the match is not unique. */
    public function sole(): mixed
    {
        return count($this->matches) === 1 ? $this->matches[0]['value'] : null;
    }

    /**
     * A bounded, deterministic sample for a report, with the true total kept
     * beside it. "3 of 47" and "3" are different statements and only the first
     * is honest, so the total never comes from the sample.
     *
     * @return array{total: int, examples: list<string>, malformed: list<string>}
     */
    public function evidence(int $cap = 5): array
    {
        return [
            'total' => $this->count(),
            'examples' => array_slice($this->paths(), 0, $cap),
            'malformed' => array_slice(
                array_map(static fn (array $m): string => $m['path'].': '.$m['outcome']->value.' ('.$m['found'].')', $this->malformed),
                0,
                $cap,
            ),
        ];
    }
}
