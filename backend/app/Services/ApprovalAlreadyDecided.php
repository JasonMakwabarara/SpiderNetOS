<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The approval was no longer pending when this request tried to decide it:
 * another request decided it first, or this one is a replay. Not an error in
 * the request — the answer is the decision already recorded.
 */
final class ApprovalAlreadyDecided extends \LogicException
{
    public function __construct(public readonly string $approvalId, public readonly ?string $status)
    {
        parent::__construct("Approval [{$approvalId}] has already been resolved (status: {$status}).");
    }
}
