<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * Everything a property handler is given, computed once.
 *
 * This was the preamble at the top of PropertyChecker::one() — seven locals
 * derived from the same four inputs, shared by twenty-two switch arms. Naming
 * it is what lets each arm become a method without every one of them
 * recomputing `dig()` and `text()`, and it is the seam the registry dispatches
 * through.
 *
 * Note that `target` and `text` are path-scoped while `output`, `steps` and the
 * fixture accessors are root-scoped. Several checks deliberately ignore `path`
 * and read the whole output — `no_unverified_figures` and `links_allowlisted`
 * are about the document, not a field of it.
 */
final readonly class PropertyContext
{
    public function __construct(
        /** The property type name, as written in the case. */
        public string $type,
        /** The string-form argument after the first colon, or null. */
        public ?string $arg,
        /** The normalised property, so array-form checks can read their own keys. @var array<string, mixed> */
        public array $p,
        /** The whole decoded output. */
        public mixed $output,
        /** The output scoped to `path`, or the whole output when there is none. */
        public mixed $target,
        /** `target` flattened to text. */
        public string $text,
        /** Root `steps[]`, or null when the output has none. @var list<mixed>|null */
        public ?array $steps,
        /** The case, for fixture-derived facts. Null in ad-hoc use. */
        public ?EvalCase $case,
        /** SkillOutputValidator's flattened verdict, or null when unavailable. @var array<string, mixed>|null */
        public ?array $validator,
    ) {}

    /**
     * The part of the output this check addresses, for the result to name.
     *
     * Null means the whole document, which is the honest answer for the checks
     * that deliberately ignore `path` — `no_unverified_figures` and
     * `links_allowlisted` are about the document, not a field of it.
     */
    public function examined(): ?string
    {
        foreach (['path', 'key'] as $k) {
            if (isset($this->p[$k]) && is_string($this->p[$k]) && $this->p[$k] !== '') {
                return $this->p[$k];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>|null  $validator
     */
    public static function for(array $p, mixed $output, ?EvalCase $case, ?array $validator): self
    {
        $path = isset($p['path']) && is_string($p['path']) && $p['path'] !== '' ? $p['path'] : null;
        $target = $path !== null ? PropertyChecker::dig($output, $path) : $output;

        return new self(
            type: (string) $p['type'],
            arg: isset($p['arg']) ? (string) $p['arg'] : null,
            p: $p,
            output: $output,
            target: $target,
            text: PropertyChecker::text($target),
            steps: is_array($output) && is_array($output['steps'] ?? null) ? array_values($output['steps']) : null,
            case: $case,
            validator: $validator,
        );
    }
}
