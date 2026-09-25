<?php

declare(strict_types=1);

namespace App\Services\Skills;

/**
 * Outcome of SkillOutputValidator::validate(): the parsed JSON (when any could
 * be recovered), the reason codes that rejected it, and whether the JSON only
 * parsed after the one repair pass.
 *
 * Reason codes: invalid_json | empty | schema | banned_phrase |
 * link_not_allowed | unverified_figure.
 */
final class ValidationResult
{
    /**
     * @param  list<array{code: string, path?: string, message: string}>  $errors
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?array $data,
        public readonly array $errors = [],
        public readonly bool $repaired = false,
    ) {}

    public static function ok(array $data, bool $repaired = false): self
    {
        return new self(true, $data, [], $repaired);
    }

    /**
     * @param  list<array{code: string, path?: string, message: string}>  $errors
     */
    public static function fail(array $errors, ?array $data = null, bool $repaired = false): self
    {
        return new self(false, $data, array_values($errors), $repaired);
    }

    /** @return list<string> distinct reason codes, in first-seen order */
    public function codes(): array
    {
        return array_values(array_unique(array_map(fn (array $e) => $e['code'], $this->errors)));
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    /** @return array{ok: bool, data: ?array, errors: array, repaired: bool} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'data' => $this->data, 'errors' => $this->errors, 'repaired' => $this->repaired];
    }
}
