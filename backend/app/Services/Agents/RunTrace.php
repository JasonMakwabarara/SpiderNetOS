<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Services\EventStore;

/**
 * Append-only trace of one run: every step becomes an agent_run_steps row
 * (ordered by seq) and, for tool and run lifecycle steps, an event_log
 * entry (`agent.tool.invoked|completed|denied|failed|awaiting_approval`,
 * `agent.run.*`) so the cockpit and projections read one stream.
 */
final class RunTrace
{
    private int $seq;

    public function __construct(
        private readonly AgentRun $run,
        private readonly EventStore $events,
    ) {
        $this->seq = (int) AgentRunStep::where('run_id', $run->id)->max('seq');
    }

    public function run(): AgentRun
    {
        return $this->run;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     */
    public function step(
        string $type,
        ?string $name = null,
        array $input = [],
        array $output = [],
        string $status = AgentRunStep::STATUS_OK,
        int $tokens = 0,
        float $cost = 0.0,
        ?int $durationMs = null,
    ): AgentRunStep {
        $step = AgentRunStep::create([
            'tenant_id' => $this->run->tenant_id,
            'run_id' => $this->run->id,
            'seq' => ++$this->seq,
            'kind' => in_array($type, AgentRunStep::KINDS, true) ? $type : AgentRunStep::KIND_NOTE,
            'name' => $name !== null ? mb_substr($name, 0, 128) : null,
            'input' => self::compact($input),
            'output' => self::compact($output),
            'status' => $status,
            'tokens' => $tokens,
            'cost_usd' => $cost,
            'duration_ms' => $durationMs,
        ]);

        $eventType = match (true) {
            $type === AgentRunStep::KIND_TOOL_CALL && $status === AgentRunStep::STATUS_DENIED => 'agent.tool.denied',
            $type === AgentRunStep::KIND_TOOL_CALL => 'agent.tool.invoked',
            $type === AgentRunStep::KIND_APPROVAL && $status === AgentRunStep::STATUS_PENDING => 'agent.tool.awaiting_approval',
            $type === AgentRunStep::KIND_TOOL_RESULT && $status === AgentRunStep::STATUS_OK => 'agent.tool.completed',
            $type === AgentRunStep::KIND_TOOL_RESULT => 'agent.tool.failed',
            default => null,
        };

        if ($eventType !== null) {
            $this->event($eventType, [
                'step_id' => $step->id,
                'seq' => $step->seq,
                'tool' => $name,
                'status' => $status,
                'reason' => $output['reason'] ?? $output['error'] ?? null,
                'approval_id' => $output['approval_id'] ?? null,
                'cost_usd' => $cost,
            ]);
        }

        return $step;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function event(string $type, array $payload = [], array $metadata = []): void
    {
        $this->events->append(
            tenantId: (string) $this->run->tenant_id,
            aggregateType: 'agent_run',
            aggregateId: (string) $this->run->id,
            eventType: $type,
            payload: $payload + [
                'run_id' => $this->run->id,
                'skill_slug' => $this->run->skill_slug,
                'workspace_id' => $this->run->workspace_id,
                'agent_id' => $this->run->agent_id,
            ],
            metadata: $metadata + ['runtime' => 'php_skill'],
        );
    }

    /** `agent.run.<status>` lifecycle event. */
    public function runEvent(string $status, array $payload = []): void
    {
        $this->event('agent.run.'.$status, $payload + ['status' => $status]);
    }

    /**
     * Keep step rows readable: long strings are truncated, nested arrays kept.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function compact(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && mb_strlen($value) > 20000) {
                $value = mb_substr($value, 0, 20000).'…';
            } elseif (is_array($value) && $depth < 6) {
                $value = self::compact($value, $depth + 1);
            } elseif (is_object($value)) {
                $value = method_exists($value, 'toArray') ? $value->toArray() : (string) json_encode($value);
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
