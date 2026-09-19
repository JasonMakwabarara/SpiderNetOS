<?php

declare(strict_types=1);

namespace App\Services\ZetKai;

/** ZetKai answered and said no, or was never connected. Carries the upstream status. */
class ZetKaiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message, $status);
    }
}
