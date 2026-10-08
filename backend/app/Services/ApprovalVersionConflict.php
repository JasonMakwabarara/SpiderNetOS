<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The approver did not approve the version that exists now.
 *
 *   missing  the request carried no version, so it cannot say what was seen
 *   stale    the resource changed after the approver's page loaded
 *   unbound  the approval was never bound to a version, so no version it
 *            names can be checked — reject it and submit again
 *
 * Not an authorisation failure and not a duplicate decision: the approver
 * may well be entitled, and the approval is still pending. They need to
 * look at the current version and decide on that.
 */
final class ApprovalVersionConflict extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
