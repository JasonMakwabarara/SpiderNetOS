<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;

/**
 * A committed single-stage decision, and where the action it owes stands.
 *
 * The decision is final once this exists. The action may not be: `done` means
 * the resource hook ran and committed; `pending` means it will be retried by
 * approvals:recover-actions; `uncertain` and `failed` need a person
 * (see ApprovalActions).
 */
final class ApprovalDecision
{
    public function __construct(
        public readonly Event $event,
        public readonly string $actionId,
        public readonly string $actionStatus,
    ) {}

    public function settled(): bool
    {
        return $this->actionStatus === ApprovalActions::DONE;
    }
}
