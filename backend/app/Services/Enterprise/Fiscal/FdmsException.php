<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Exceptions\DomainException;

final class FdmsException extends DomainException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        // No answer or a 5xx: FDMS may or may not have taken the request.
        public readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct($message);
    }
}
