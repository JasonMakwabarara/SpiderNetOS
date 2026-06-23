<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Executes deterministic flow node actions (digest, reminder, notify, log, webhook).
 */
class NodeActionRunner
{
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
            'log' => $this->runLog($nodeConfig, $context),
            default => $this->runLog($nodeConfig, $context),
        };
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
