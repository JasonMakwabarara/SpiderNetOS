<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AgentRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tells the cockpit (Echo, channel tenant.{id}) that a run changed status
 * so the God's Eye board, the workspace view and /agents/runs/:id refetch.
 */
class AgentRunUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<string, mixed> $summary */
    public function __construct(
        public string $tenantId,
        public string $runId,
        public string $status,
        public array $summary = [],
    ) {}

    public static function fromRun(AgentRun $run): self
    {
        return new self((string) $run->tenant_id, (string) $run->id, (string) $run->status, [
            'skill_slug' => $run->skill_slug,
            'workspace_id' => $run->workspace_id,
            'agent_id' => $run->agent_id,
            'mode' => $run->mode,
            'error' => $run->error,
            'questions' => (array) ($run->questions ?? []),
            'approval_id' => ($run->outputs ?? [])['approval_id'] ?? (($run->state ?? [])['pending_tool_call']['approval_id'] ?? null),
            'cost_usd' => (float) $run->cost_usd,
            'tokens' => (int) $run->tokens,
        ]);
    }

    /** Broadcast when a driver is configured; never let a socket outage fail a run. */
    public static function safeBroadcast(AgentRun $run): void
    {
        $driver = (string) config('broadcasting.default', 'null');
        if ($driver === '' || $driver === 'null' || $driver === 'log') {
            return;
        }

        try {
            broadcast(self::fromRun($run));
        } catch (\Throwable $e) {
            Log::warning('agent.run.updated broadcast failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
        }
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId)];
    }

    public function broadcastAs(): string
    {
        return 'agent.run.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status,
            'at' => now()->toIso8601String(),
        ] + $this->summary;
    }
}
