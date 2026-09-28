<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

/** 402 with the same checkout-hint shape RequirePackEntitlement returns. */
class PackNotEntitledException extends AgentRuntimeException
{
    /** @param array<string, mixed> $hint */
    public function __construct(string $packId, array $hint = [])
    {
        parent::__construct("This skill requires the {$packId} pack.", 'pack_not_entitled', 402, $hint + ['checkout_hint' => true, 'pack_id' => $packId]);
    }
}
