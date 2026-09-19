<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HubspotAdapter — Phase D
 *
 * HubSpot CRM integration.
 * Credentials: private_app_token (PAT) stored in tenant_secrets.
 */
class HubspotAdapter extends CrmAdapter
{
    private const BASE_URL = 'https://api.hubapi.com';

    public function upsertContact(array $contact): array
    {
        $token = $this->credentials['private_app_token'] ?? null;
        if (! $token) {
            return ['success' => false, 'contact_id' => null, 'error' => 'Missing HubSpot token'];
        }

        try {
            $properties = array_filter([
                'firstname' => explode(' ', $contact['name'])[0] ?? $contact['name'],
                'lastname' => explode(' ', $contact['name'])[1] ?? '',
                'phone' => $contact['phone'] ?? null,
                'email' => $contact['email'] ?? null,
            ]);

            // Try to find by phone or email first (upsert)
            $searchResp = Http::withToken($token)->post(self::BASE_URL.'/crm/v3/objects/contacts/search', [
                'filterGroups' => [[
                    'filters' => [[
                        'propertyName' => 'phone',
                        'operator' => 'EQ',
                        'value' => $contact['phone'],
                    ]],
                ]],
                'properties' => ['hs_object_id', 'phone', 'email'],
                'limit' => 1,
            ]);

            $existingId = $searchResp->successful()
                ? ($searchResp->json()['results'][0]['id'] ?? null)
                : null;

            if ($existingId) {
                // Update existing
                Http::withToken($token)->patch(self::BASE_URL."/crm/v3/objects/contacts/{$existingId}", [
                    'properties' => $properties,
                ]);

                return ['success' => true, 'contact_id' => $existingId, 'error' => null];
            }

            // Create new
            $createResp = Http::withToken($token)->post(self::BASE_URL.'/crm/v3/objects/contacts', [
                'properties' => $properties,
            ]);

            if (! $createResp->successful()) {
                return ['success' => false, 'contact_id' => null, 'error' => 'HubSpot create failed'];
            }

            return ['success' => true, 'contact_id' => $createResp->json()['id'], 'error' => null];

        } catch (\Throwable $e) {
            Log::error('hubspot.upsert_contact_failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'contact_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function logCallActivity(array $activity): bool
    {
        $token = $this->credentials['private_app_token'] ?? null;
        if (! $token) {
            return false;
        }

        try {
            $notes = "Duration: {$activity['duration_seconds']}s | Sentiment: {$activity['sentiment']}\n\n{$activity['summary']}";
            if (! empty($activity['transcript_url'])) {
                $notes .= "\n\nTranscript: {$activity['transcript_url']}";
            }

            $resp = Http::withToken($token)->post(self::BASE_URL.'/crm/v3/objects/notes', [
                'properties' => [
                    'hs_note_body' => $notes,
                    'hs_timestamp' => now()->toIso8601String(),
                ],
                'associations' => [[
                    'to' => ['id' => $activity['contact_id']],
                    'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 202]],
                ]],
            ]);

            return $resp->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function createTask(array $task): bool
    {
        $token = $this->credentials['private_app_token'] ?? null;
        if (! $token) {
            return false;
        }

        try {
            $resp = Http::withToken($token)->post(self::BASE_URL.'/crm/v3/objects/tasks', [
                'properties' => [
                    'hs_task_subject' => $task['task'],
                    'hs_task_status' => 'NOT_STARTED',
                    'hs_task_priority' => 'MEDIUM',
                    'hs_timestamp' => $task['due_date'] ? date('c', strtotime($task['due_date'])) : now()->addDay()->toIso8601String(),
                ],
                'associations' => [[
                    'to' => ['id' => $task['contact_id']],
                    'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 204]],
                ]],
            ]);

            return $resp->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
