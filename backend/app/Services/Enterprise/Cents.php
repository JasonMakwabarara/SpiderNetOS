<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

final class Cents
{
    public static function from(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function toString(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function applyRate(int $cents, float $rate): int
    {
        return (int) round($cents * $rate);
    }
}
