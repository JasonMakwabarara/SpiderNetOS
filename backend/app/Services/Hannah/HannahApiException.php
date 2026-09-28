<?php

declare(strict_types=1);

namespace App\Services\Hannah;

/** Hannah answered, and said no. Carries the upstream status for the caller to reflect. */
class HannahApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502, public readonly array $body = [])
    {
        parent::__construct($message, $status);
    }
}
