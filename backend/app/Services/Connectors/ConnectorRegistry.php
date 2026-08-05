<?php

declare(strict_types=1);

namespace App\Services\Connectors;

/**
 * The connector catalogue: what a tenant can connect, how it authenticates,
 * what fields the connect form needs, and which actions agents may invoke.
 *
 * This is the single source of truth for the cockpit's Connectors UI and for
 * the intelligence layer's tool discovery — adding a connector means adding an
 * entry here plus a class implementing ConnectorContract.
 */
class ConnectorRegistry
{
    /**
     * auth: api_key | oauth2 | webhook | basic
     * fields: credential inputs (secret => masked in UI)
     * actions: agent-invocable capabilities
     *
     * @var array<string, array<string,mixed>>
     */
    private const CATALOGUE = [
        // ─── Messaging & collaboration ──────────────────────────────
        'slack' => [
            'name' => 'Slack',
            'category' => 'messaging',
            'auth' => 'api_key',
            'description' => 'Post updates, alerts, and daily briefs into a Slack channel.',
            'fields' => [
                ['key' => 'bot_token', 'label' => 'Bot token (xoxb-…)', 'secret' => true, 'required' => true],
                ['key' => 'default_channel', 'label' => 'Default channel', 'secret' => false, 'required' => false],
            ],
            'actions' => ['post_message'],
            'docs_url' => 'https://api.slack.com/authentication/token-types',
        ],

        // ─── Generic outbound (breadth multiplier) ──────────────────
        'webhook' => [
            'name' => 'Webhook / Zapier',
            'category' => 'automation',
            'auth' => 'webhook',
            'description' => 'Send events to any HTTPS endpoint — Zapier, Make, n8n, or your own service.',
            'fields' => [
                ['key' => 'url', 'label' => 'Endpoint URL', 'secret' => false, 'required' => true],
                ['key' => 'secret', 'label' => 'Signing secret (optional)', 'secret' => true, 'required' => false],
            ],
            'actions' => ['send_event'],
            'docs_url' => null,
        ],
        'http_api' => [
            'name' => 'Custom HTTP API',
            'category' => 'automation',
            'auth' => 'api_key',
            'description' => 'Call any REST API with a bearer token — for in-house or unsupported tools.',
            'fields' => [
                ['key' => 'base_url', 'label' => 'Base URL', 'secret' => false, 'required' => true],
                ['key' => 'token', 'label' => 'Bearer token', 'secret' => true, 'required' => false],
            ],
            'actions' => ['request'],
            'docs_url' => null,
        ],

        // ─── Calendar (existing adapters) ───────────────────────────
        'google_calendar' => [
            'name' => 'Google Calendar',
            'category' => 'calendar',
            'auth' => 'oauth2',
            'description' => 'Book appointments and read availability on Google Calendar.',
            'fields' => [
                ['key' => 'access_token', 'label' => 'Access token', 'secret' => true, 'required' => true],
                ['key' => 'refresh_token', 'label' => 'Refresh token', 'secret' => true, 'required' => false],
                ['key' => 'calendar_id', 'label' => 'Calendar ID', 'secret' => false, 'required' => false],
            ],
            'actions' => ['book', 'available_slots', 'cancel'],
            'docs_url' => 'https://developers.google.com/calendar',
        ],
        'cal_com' => [
            'name' => 'Cal.com',
            'category' => 'calendar',
            'auth' => 'api_key',
            'description' => 'Book appointments through Cal.com scheduling links.',
            'fields' => [
                ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'required' => true],
                ['key' => 'event_type_id', 'label' => 'Event type ID', 'secret' => false, 'required' => false],
            ],
            'actions' => ['book', 'available_slots', 'cancel'],
            'docs_url' => 'https://cal.com/docs',
        ],

        // ─── CRM (existing adapters) ────────────────────────────────
        'hubspot' => [
            'name' => 'HubSpot',
            'category' => 'crm',
            'auth' => 'api_key',
            'description' => 'Sync contacts, log activity, and create follow-up tasks in HubSpot.',
            'fields' => [
                ['key' => 'access_token', 'label' => 'Private app token', 'secret' => true, 'required' => true],
            ],
            'actions' => ['upsert_contact', 'log_activity', 'create_task'],
            'docs_url' => 'https://developers.hubspot.com/docs/api/private-apps',
        ],
        'salesforce' => [
            'name' => 'Salesforce',
            'category' => 'crm',
            'auth' => 'oauth2',
            'description' => 'Sync contacts and activity with Salesforce.',
            'fields' => [
                ['key' => 'instance_url', 'label' => 'Instance URL', 'secret' => false, 'required' => true],
                ['key' => 'access_token', 'label' => 'Access token', 'secret' => true, 'required' => true],
            ],
            'actions' => ['upsert_contact', 'log_activity', 'create_task'],
            'docs_url' => 'https://developer.salesforce.com/docs',
        ],
    ];

    /** @return array<string, array<string,mixed>> */
    public function all(): array
    {
        return self::CATALOGUE;
    }

    /** Catalogue entries flattened for API/UI, with the provider id inlined. */
    public function catalogue(): array
    {
        return array_map(
            fn (string $id, array $meta) => ['provider' => $id] + $meta,
            array_keys(self::CATALOGUE),
            self::CATALOGUE,
        );
    }

    public function has(string $provider): bool
    {
        return isset(self::CATALOGUE[$provider]);
    }

    /** @return array<string,mixed>|null */
    public function get(string $provider): ?array
    {
        return self::CATALOGUE[$provider] ?? null;
    }

    /** The integration `type` column value for a provider (its category). */
    public function typeFor(string $provider): string
    {
        return (string) (self::CATALOGUE[$provider]['category'] ?? 'other');
    }

    /** @return list<string> */
    public function actionsFor(string $provider): array
    {
        return (array) (self::CATALOGUE[$provider]['actions'] ?? []);
    }

    /**
     * Validate a credentials payload against the provider's required fields.
     *
     * @return list<string> missing field keys
     */
    public function missingFields(string $provider, array $credentials): array
    {
        $missing = [];
        foreach ((array) (self::CATALOGUE[$provider]['fields'] ?? []) as $field) {
            if (($field['required'] ?? false) && empty($credentials[$field['key']])) {
                $missing[] = $field['key'];
            }
        }

        return $missing;
    }
}
