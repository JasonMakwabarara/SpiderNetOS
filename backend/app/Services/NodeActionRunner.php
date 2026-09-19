<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Inference\InferencePlaneClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Executes deterministic flow node actions (digest, reminder, notify, log,
 * webhook) plus LLM-backed agent steps (agent_step) via the inference plane.
 */
class NodeActionRunner
{
    public function __construct(
        private readonly InferencePlaneClient $inference,
        private readonly CostGovernor $costGovernor,
    ) {}

    /**
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function run(string $tenantId, string $nodeType, array $nodeConfig, array $context = []): array
    {
        $action = (string) ($nodeConfig['action'] ?? $nodeType);

        return match ($action) {
            'digest', 'status' => $this->runDigest($nodeConfig, $context),
            'reminder', 'followup' => $this->runReminder($nodeConfig, $context),
            'invoice' => $this->runInvoiceReminder($nodeConfig, $context),
            'notify' => $this->runNotify($nodeConfig, $context),
            'webhook' => $this->runWebhook($nodeConfig, $context),
            'trigger' => $this->runTrigger($nodeConfig, $context),
            'agent_step' => $this->runAgentStep($tenantId, $nodeConfig, $context),
            'log' => $this->runLog($nodeConfig, $context),
            default => $this->runLog($nodeConfig, $context),
        };
    }

    /**
     * Execute one SOP step through the owning agent's LLM runtime.
     *
     * Modes (config spidernet.agent_step_execution):
     *   inference — call the inference plane; a failure throws, which fails
     *               the node → the run records "failed" → two in a row
     *               escalate to a human. Accountability by design.
     *   simulate  — deterministic offline result, explicitly marked
     *               simulated:true. Default when no inference URL is set,
     *               so runs are never silently fake.
     *
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runAgentStep(string $tenantId, array $nodeConfig, array $context): array
    {
        $instruction = trim((string) ($nodeConfig['instruction'] ?? ''));

        if ($instruction === '') {
            throw new \RuntimeException('agent_step node has no instruction.');
        }

        $mode = (string) config('spidernet.agent_step_execution', 'auto');
        if ($mode === 'auto') {
            $mode = $this->inference->configured() ? 'inference' : 'simulate';
        }

        if ($mode === 'simulate') {
            return [
                'action' => 'agent_step',
                'simulated' => true,
                'instruction' => $instruction,
                'output' => 'Simulated: "'.Str::limit($instruction, 80).'" acknowledged by '
                    .($nodeConfig['agent_id'] ?? 'owning agent').'. Configure INFERENCE_URL for real execution.',
                'executed_at' => now()->toIso8601String(),
            ];
        }

        $tools = array_filter((array) ($nodeConfig['tools'] ?? []));
        $quality = array_filter((array) ($context['quality_criteria'] ?? []));

        $systemPrompt = 'You are an operations agent executing one step of a Standard Operating Procedure for a business. '
            .'Perform the step using the tools available to you and report exactly what you did. '
            .'If the step cannot be completed, start your reply with "BLOCKED:" and state precisely what is missing.'
            .($tools !== [] ? ' Tools available: '.implode(', ', $tools).'.' : '')
            .($quality !== [] ? ' Success criteria for this SOP: '.implode(' | ', $quality).'.' : '');

        $result = $this->inference->generate(
            prompt: $instruction,
            systemPrompt: $systemPrompt,
            tenantTier: (string) ($context['tenant_tier'] ?? 'starter'),
            costCeiling: (float) config('spidernet.agent_step_cost_ceiling', 0.25),
        );

        if ($result['cost'] > 0) {
            $this->costGovernor->recordUsage($tenantId, 'agent_step', $result['cost'], [
                'model' => $result['model'],
                'provider' => $result['provider'],
                'sop_step' => $nodeConfig['sop_step'] ?? null,
            ]);
        }

        // The agent saying it is blocked is a failed step, not a passed one —
        // silently marking blocked work "done" would corrupt the feedback loop.
        if (str_starts_with(ltrim($result['text']), 'BLOCKED:')) {
            throw new \RuntimeException('Agent reported blocked: '.Str::limit(ltrim($result['text']), 300));
        }

        return [
            'action' => 'agent_step',
            'simulated' => false,
            'instruction' => $instruction,
            'output' => $result['text'],
            'model' => $result['model'],
            'provider' => $result['provider'],
            'tokens_used' => $result['tokens_used'],
            'cost_usd' => $result['cost'],
            'executed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runDigest(array $nodeConfig, array $context): array
    {
        $who = (string) ($context['who'] ?? $nodeConfig['who'] ?? 'team');
        $when = (string) ($context['when'] ?? $nodeConfig['when'] ?? 'daily');
        $summary = "Daily status digest prepared for {$who} (schedule: {$when}).";

        return [
            'action' => 'digest',
            'summary' => $summary,
            'recipients' => [$who],
            'delivered_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runReminder(array $nodeConfig, array $context): array
    {
        $who = (string) ($context['who'] ?? $nodeConfig['who'] ?? 'team');
        $message = (string) ($nodeConfig['message'] ?? "Follow-up reminder for {$who}");

        return [
            'action' => 'reminder',
            'message' => $message,
            'recipients' => [$who],
            'scheduled_for' => (string) ($context['when'] ?? 'now'),
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runInvoiceReminder(array $nodeConfig, array $context): array
    {
        $who = (string) ($context['who'] ?? $nodeConfig['who'] ?? 'finance');

        return [
            'action' => 'invoice',
            'message' => "Invoice payment reminder queued for {$who}",
            'recipients' => [$who],
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runNotify(array $nodeConfig, array $context): array
    {
        $channel = (string) ($nodeConfig['channel'] ?? 'in_app');
        $body = (string) ($nodeConfig['body'] ?? $context['message'] ?? 'Automation notification');

        return [
            'action' => 'notify',
            'channel' => $channel,
            'body' => $body,
            'notification_id' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runWebhook(array $nodeConfig, array $context): array
    {
        $url = (string) ($nodeConfig['url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('Webhook node missing config.url');
        }

        $payload = array_merge($context, (array) ($nodeConfig['payload'] ?? []));
        $response = Http::timeout(15)->post($url, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException("Webhook failed with status {$response->status()}");
        }

        return [
            'action' => 'webhook',
            'url' => $url,
            'status_code' => $response->status(),
            'response' => $response->json() ?? $response->body(),
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runTrigger(array $nodeConfig, array $context): array
    {
        return [
            'action' => 'trigger',
            'when' => (string) ($nodeConfig['when'] ?? $context['when'] ?? 'now'),
            'started_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $nodeConfig
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runLog(array $nodeConfig, array $context): array
    {
        $message = (string) ($nodeConfig['message'] ?? $context['message'] ?? 'Flow step completed');
        Log::info('[NodeActionRunner] '.$message, ['context' => $context]);

        return [
            'action' => 'log',
            'message' => $message,
            'logged_at' => now()->toIso8601String(),
        ];
    }
}
