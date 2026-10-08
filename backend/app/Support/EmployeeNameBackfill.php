<?php

declare(strict_types=1);

namespace App\Support;

class EmployeeNameBackfill
{
    /**
     * Split a legacy display name without failing on a single token.
     *
     * @return array{first_name: string, surname: string}
     */
    public static function split(string $name): array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return ['first_name' => '', 'surname' => ''];
        }

        $parts = preg_split('/\s+/', $trimmed, 2) ?: [];

        return [
            'first_name' => $parts[0] ?? '',
            'surname' => $parts[1] ?? '',
        ];
    }
}
