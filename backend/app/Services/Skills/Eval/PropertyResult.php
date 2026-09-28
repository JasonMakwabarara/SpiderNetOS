<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * What one property check concluded, and on what.
 *
 * Replaces the `[status, detail]` tuple the twenty-one handlers returned. The
 * tuple could say a check went red; it could not say which check, for what
 * reason, or over which part of the output — so a negative test could only
 * assert the colour, and a colour is shared by a detected violation and a check
 * that never ran.
 *
 * Four things a negative test can now pin: the type is implemented (the registry
 * says so), the status is `failed`, the `reason` is the intended one, and `path`
 * is the part of the output actually examined.
 *
 * `evidence` carries *every* offending item rather than the first. One
 * low-severity match must never be the whole explanation while a more
 * consequential one sits unreported in the same output — the concealment the
 * deleted `no_banned_phrase` shipped, a level up.
 *
 * Statuses stay `passed`/`failed`/`skipped` here. The five-outcome accounting is
 * derived from `reason` in a later commit, which is why the enum draws
 * distinctions today's status flattens.
 */
final readonly class PropertyResult
{
    /** @param list<string> $evidence */
    private function __construct(
        public string $status,
        public Reason $reason,
        public string $detail,
        public ?string $path,
        public array $evidence,
        /**
         * How many subjects the boundary resolved, or null where the property
         * never reached it (a dispatch failure, or a root check that resolves
         * no path).
         *
         * A pass has to name its denominator, and until now it named it only in
         * prose - `detail` carries "3 subject(s) satisfy ...", which a test can
         * only read by string-matching a sentence this suite deliberately
         * unfroze. Zero here with a passed status is the vacuous truth in one
         * field, and it is now assertable rather than inferable.
         */
        public ?int $subjects = null,
    ) {}

    /** The same conclusion, carrying the count the boundary resolved. */
    public function over(int $subjects): self
    {
        return new self($this->status, $this->reason, $this->detail, $this->path, $this->evidence, $subjects);
    }

    /** @param list<string> $evidence */
    public static function pass(string $detail, ?string $path = null, array $evidence = []): self
    {
        return new self('passed', Reason::Satisfied, $detail, $path, $evidence);
    }

    /** @param list<string> $evidence */
    public static function fail(Reason $reason, string $detail, ?string $path = null, array $evidence = []): self
    {
        return new self('failed', $reason, $detail, $path, $evidence);
    }

    /**
     * A dependency the check needed was absent, so nothing about the output was
     * established either way.
     *
     * `$statusToday` is deliberate. Four call sites reach this, and they do not
     * agree: `schema_valid` and `respects_never_say` return `skipped`, while
     * `cites_fact` and `mentions_proof_point` return `failed`. That
     * inconsistency is older than this commit, and this commit introduces the
     * vocabulary without moving the gate — so each site keeps the status it
     * already had.
     *
     * Commit 6 reads `Reason::isDependencyMissing()` and makes all four
     * `unavailable`, which blocks. That tightens the two that skip and leaves
     * the two that fail blocking. Tightening later is safe; reclassifying the
     * failing pair to `skipped` now would open a window in which a missing
     * fixture reads as success — the exact shape of the vacuous truth this
     * stage exists to remove.
     *
     * @param  'passed'|'failed'|'skipped'  $statusToday
     * @param  list<string>  $evidence
     */
    public static function dependencyMissing(Reason $reason, string $detail, string $statusToday, ?string $path = null, array $evidence = []): self
    {
        return new self($statusToday, $reason, $detail, $path, $evidence);
    }

    /** The property named a type nothing declares, or handed one an argument it cannot use. */
    public static function dispatchFailure(Reason $reason, string $detail): self
    {
        return new self('failed', $reason, $detail, null, []);
    }

    /** @return array{status: string, reason: string, detail: string, path: ?string, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason->value,
            'detail' => $this->detail,
            'path' => $this->path,
            'evidence' => $this->evidence,
        ];
    }
}
