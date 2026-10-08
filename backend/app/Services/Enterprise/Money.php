<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;

final class Money
{
    public static function code(string $currency): string
    {
        $code = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3,4}$/', $code)) {
            throw new DomainException('Currency must be a 3 or 4 letter code.');
        }

        return $code;
    }

    public static function positive(mixed $amount): string
    {
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new DomainException('Amount must be greater than zero.');
        }

        return number_format((float) $amount, 4, '.', '');
    }
}
