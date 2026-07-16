<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SalesforceAdapter — Phase D
 *
 * Salesforce CRM stub. Full implementation requires OAuth2 SFDC flow.
 * Credentials: { instance_url, access_token }
 */
class SalesforceAdapter extends CrmAdapter
{
    public function upsertContact(array $contact): array
    {
        $token       = $this->credentials['access_token'] ?? null;
        $instanceUrl = $this->credentials['instance_url'] ?? null;

        if (!$token || !$instanceUrl) {
            return ['success' => false, 'contact_id' => null, 'error' => 'Missing Salesforce credentials'];
        }

        try {
            $response = Http::withToken($token)
                ->post("{$instanceUrl}/services/data/v59.0/sobjects/Contact/", [
                    'LastName' => $contact['name'],
                    'Phone'    => $contact['phone'] ?? null,
                    'Email'    => $contact['email'] ?? null,
                ]);

            if (!$response->successful()) {
                return ['success' => false, 'contact_id' => null, 'error' => 'Salesforce API error'];
            }

            return ['success' => true, 'contact_id' => $response->json()['id'] ?? null, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('salesforce.upsert_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'contact_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function logCallActivity(array $activity): bool
    {
        // TODO: implement via SFDC Task object
        Log::info('salesforce.log_call_stub', ['account' => $this->tenantId]);
        return true;
    }

    public function createTask(array $task): bool
    {
        // TODO: implement via SFDC Task object
        return true;
    }
}
