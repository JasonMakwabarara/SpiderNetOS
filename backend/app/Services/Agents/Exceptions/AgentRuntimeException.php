<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

/**
 * Base class for every failure the PHP skill runtime raises. Each subclass
 * carries an HTTP status and a stable `code` string so the API controllers
 * map them without a switch.
 */
class AgentRuntimeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'agent_runtime_error',
        public readonly int $httpStatus = 500,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toResponse(): array
    {
        return ['error' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
