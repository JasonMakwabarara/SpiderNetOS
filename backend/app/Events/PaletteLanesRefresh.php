<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies Cockpit (Echo) to refetch command-palette live lanes (approvals + traces).
 */
class PaletteLanesRefresh implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $tenantId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId)];
    }

    public function broadcastAs(): string
    {
        return 'palette.lanes.refresh';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['at' => now()->toIso8601String()];
    }
}
