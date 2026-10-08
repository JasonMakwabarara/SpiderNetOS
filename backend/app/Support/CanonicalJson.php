<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One byte sequence for one value, whatever order its keys arrived in.
 *
 * Object keys are sorted recursively; list order is kept, because in a list
 * order is content. The encoding flags are fixed. Hash only values read back
 * from the database: Postgres `jsonb` reorders an object's keys while the
 * SQLite test lane keeps them, so a hash of the in-memory array written a
 * moment ago would pass every local test and mismatch in production.
 */
final class CanonicalJson
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return json_encode(self::normalise($value), self::FLAGS);
    }

    /** `sha256:` followed by 64 hex characters. */
    public static function hash(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::encode($value));
    }

    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalise(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::normalise(...), $value);
    }
}
