<?php

declare(strict_types=1);

namespace App\Services\Outreach\Channels;

/**
 * This channel drafts and does not deliver.
 *
 * An exception rather than a falsy return on purpose: a silent no-op on send
 * looks identical to a successful send in every log, dashboard and outcome
 * ledger, and the business would go on believing it had contacted people it
 * never contacted.
 */
class ChannelCannotSendException extends \RuntimeException {}
