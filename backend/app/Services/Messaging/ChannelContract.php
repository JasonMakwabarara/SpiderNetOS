<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Lead;

/**
 * A messaging channel adapter. New channels (SMS, iMessage/Sendblue, …) bind to
 * this contract so MessageDispatchService can route by channel name without
 * knowing provider details.
 */
interface ChannelContract
{
    /**
     * @param  array<string, mixed>  $options  channel-specific extras (e.g. ['subject' => ...] for email)
     * @return array{success: bool, provider_message_id?: ?string, error?: string}
     */
    public function send(Lead $lead, string $body, array $options = []): array;
}
