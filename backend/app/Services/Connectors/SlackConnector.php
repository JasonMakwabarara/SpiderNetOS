<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use Illuminate\Support\Facades\Http;

/**
 * Slack via a bot token (chat.postMessage). Used for briefs, approval nudges,
 * and anomaly alerts into a channel.
 */
class SlackConnector implements ConnectorContract
{
    public function __construct(
        private readonly string $tenantId,
        private readonly array $credentials,
        private readonly array $config = [],
    ) {}

    public function test(): array
    {
        $token = (string) ($this->credentials['bot_token'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'error' => 'Missing bot_token.'];
        }

        $res = Http::withToken($token)->get('https://slack.com/api/auth.test');
        $body = $res->json() ?? [];

        return ($res->successful() && ($body['ok'] ?? false))
            ? ['ok' => true, 'detail' => ['team' => $body['team'] ?? null, 'bot' => $body['user'] ?? null]]
            : ['ok' => false, 'error' => $body['error'] ?? 'Slack auth.test failed.'];
    }

    public function execute(string $action, array $params = []): array
    {
        return match ($action) {
            'post_message' => $this->postMessage($params),
            default => ['success' => false, 'error' => "Unsupported Slack action: {$action}"],
        };
    }

    private function postMessage(array $params): array
    {
        $token = (string) ($this->credentials['bot_token'] ?? '');
        $channel = (string) ($params['channel'] ?? $this->credentials['default_channel'] ?? $this->config['default_channel'] ?? '');
        $text = (string) ($params['text'] ?? '');

        if ($token === '' || $channel === '' || $text === '') {
            return ['success' => false, 'error' => 'token, channel and text are required.'];
        }

        $res = Http::withToken($token)->post('https://slack.com/api/chat.postMessage', [
            'channel' => $channel,
            'text' => $text,
        ]);
        $body = $res->json() ?? [];

        return ($res->successful() && ($body['ok'] ?? false))
            ? ['success' => true, 'data' => ['ts' => $body['ts'] ?? null, 'channel' => $body['channel'] ?? $channel]]
            : ['success' => false, 'error' => $body['error'] ?? 'Slack postMessage failed.'];
    }
}
