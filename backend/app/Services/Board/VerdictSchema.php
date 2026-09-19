<?php

declare(strict_types=1);

namespace App\Services\Board;

/**
 * What a seat must return, and what happens when it does not.
 *
 * A board whose seats answer in prose is a focus group. The structure is the
 * product: a stance you can put in a table, a confidence you can weigh, one
 * number you can check later, and the thing that would change the seat's mind
 * — which is the only part that makes the advice falsifiable.
 *
 * Validation is strict about shape and lenient about wrapping, because models
 * fence JSON in markdown roughly half the time and failing a whole board
 * session over three backticks would be silly.
 */
class VerdictSchema
{
    public const STANCES = ['yes', 'no', 'not_yet', 'depends'];

    public const REQUIRED = ['stance', 'confidence', 'one_number', 'what_would_change_my_mind', 'reasoning'];

    public const MAX_REASONING = 2400;

    public const MAX_KILL_CRITERIA = 4;

    /**
     * @return array{ok: bool, verdict: array<string, mixed>, errors: list<string>}
     */
    public static function parse(string $raw): array
    {
        $decoded = self::decode($raw);
        if ($decoded === null) {
            return ['ok' => false, 'verdict' => [], 'errors' => ['the seat did not return JSON']];
        }

        $errors = [];

        $stance = is_string($decoded['stance'] ?? null) ? strtolower(trim($decoded['stance'])) : '';
        $stance = str_replace([' ', '-'], '_', $stance);
        if (! in_array($stance, self::STANCES, true)) {
            $errors[] = 'stance must be one of '.implode(', ', self::STANCES);
            $stance = 'depends';
        }

        $confidence = $decoded['confidence'] ?? null;
        if (! is_numeric($confidence)) {
            $errors[] = 'confidence must be a number between 0 and 1';
            $confidence = 0.5;
        }
        $confidence = max(0.0, min(1.0, (float) $confidence));

        $verdict = [
            'stance' => $stance,
            'confidence' => round($confidence, 2),
            'one_number' => self::text($decoded['one_number'] ?? null, 200),
            'what_would_change_my_mind' => self::text($decoded['what_would_change_my_mind'] ?? null, 400),
            'kill_criteria' => array_slice(array_values(array_filter(array_map(
                fn ($c): string => self::text($c, 240),
                (array) ($decoded['kill_criteria'] ?? []),
            ), fn (string $c): bool => $c !== '')), 0, self::MAX_KILL_CRITERIA),
            'reasoning' => self::text($decoded['reasoning'] ?? null, self::MAX_REASONING),
        ];

        foreach (self::REQUIRED as $key) {
            if (($verdict[$key] ?? '') === '') {
                $errors[] = "{$key} is empty";
            }
        }

        return ['ok' => $errors === [], 'verdict' => $verdict, 'errors' => $errors];
    }

    /** What the seats are told to return, appended to every seat prompt. */
    public static function instruction(): string
    {
        return implode("\n", [
            'Return one JSON object and nothing else:',
            '{',
            '  "stance": "yes" | "no" | "not_yet" | "depends",',
            '  "confidence": 0.0-1.0,',
            '  "one_number": "the single number you would watch, and what it is",',
            '  "what_would_change_my_mind": "the fact or result that would move you",',
            '  "kill_criteria": ["at most 4: conditions that would mean stop"],',
            '  "reasoning": "your case, in your own voice, under 2000 characters"',
            '}',
            'No markdown, no preamble, no text outside the object.',
        ]);
    }

    /** Tolerates ```json fences and leading prose; returns null when there is no object at all. */
    private static function decode(string $raw): ?array
    {
        $trimmed = trim($raw);

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private static function text(mixed $value, int $max): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_map('strval', array_filter($value, 'is_scalar')));
        }
        if (! is_scalar($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $max);
    }
}
