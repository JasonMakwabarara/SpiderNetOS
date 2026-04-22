<?php

declare(strict_types=1);

namespace App\Services\Integrations;

/**
 * CrmAdapter — Phase D abstract base
 *
 * Concrete implementations: HubspotAdapter, SalesforceAdapter.
 */
abstract class CrmAdapter
{
    public function __construct(
        protected readonly string $tenantId,
        protected readonly array  $credentials,
    ) {}

    /**
     * Create or update a contact.
     *
     * @param  array{name: string, phone: string, email: string|null, metadata: array} $contact
     * @return array{success: bool, contact_id: string|null, error: string|null}
     */
    abstract public function upsertContact(array $contact): array;

    /**
     * Log a call activity against a contact.
     *
     * @param  array{
     *   contact_id: string,
     *   duration_seconds: int,
     *   summary: string,
     *   sentiment: string,
     *   transcript_url: string|null,
     * } $activity
     */
    abstract public function logCallActivity(array $activity): bool;

    /**
     * Create a follow-up task.
     *
     * @param  array{contact_id: string, task: string, due_date: string|null, owner_id: string|null} $task
     */
    abstract public function createTask(array $task): bool;

    // ─── Factory ──────────────────────────────────────────────────────────────

    public static function make(string $tenantId, string $provider, array $credentials): static
    {
        return match ($provider) {
            'hubspot'     => new HubspotAdapter($tenantId, $credentials),
            'salesforce'  => new SalesforceAdapter($tenantId, $credentials),
            default       => throw new \InvalidArgumentException("Unknown CRM provider: {$provider}"),
        };
    }
}
