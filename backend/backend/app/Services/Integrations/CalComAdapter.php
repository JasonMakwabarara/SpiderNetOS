<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CalComAdapter — Phase D
 *
 * Integrates with Cal.com API v2.
 * Credentials: API key stored in tenant_secrets.
 */
class CalComAdapter extends CalendarAdapter
{
    private const BASE_URL = 'https://api.cal.com/v2';

    public function book(array $event): array
    {
        $apiKey    = $this->credentials['api_key'] ?? null;
        $eventTypeId = $this->credentials['event_type_id'] ?? null;

        if (!$apiKey || !$eventTypeId) {
            return ['success' => false, 'event_id' => null, 'calendar_link' => null, 'error' => 'Missing Cal.com credentials'];
        }

        try {
            $startDatetime = $event['date'] . 'T' . $event['time'] . ':00.000Z';

            $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->post(self::BASE_URL . '/bookings', [
                    'eventTypeId' => (int) $eventTypeId,
                    'start'       => $startDatetime,
                    'attendee'    => [
                        'name'     => $event['attendee_name'],
                        'email'    => $event['attendee_email'] ?? 'noemail@placeholder.com',
                        'timeZone' => 'America/New_York',
                        'language' => 'en',
                    ],
                    'metadata'    => [
                        'phone'  => $event['attendee_phone'] ?? '',
                        'source' => 'voice_agent',
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('calcom.book_failed', [
                    'tenant_id' => $this->tenantId,
                    'status'    => $response->status(),
                ]);
                return ['success' => false, 'event_id' => null, 'calendar_link' => null, 'error' => 'Cal.com API error'];
            }

            $data = $response->json()['data'] ?? $response->json();

            return [
                'success'       => true,
                'event_id'      => (string) ($data['uid'] ?? $data['id'] ?? ''),
                'calendar_link' => null,
                'error'         => null,
            ];
        } catch (\Throwable $e) {
            Log::error('calcom.book_exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'event_id' => null, 'calendar_link' => null, 'error' => $e->getMessage()];
        }
    }

    public function getAvailableSlots(string $date, ?string $calendarId = null): array
    {
        $apiKey      = $this->credentials['api_key'] ?? null;
        $eventTypeId = $this->credentials['event_type_id'] ?? null;

        if (!$apiKey || !$eventTypeId) {
            return ['slots' => []];
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->get(self::BASE_URL . '/slots', [
                    'eventTypeId' => $eventTypeId,
                    'startTime'   => $date . 'T00:00:00Z',
                    'endTime'     => $date . 'T23:59:59Z',
                ]);

            if (!$response->successful()) {
                return ['slots' => []];
            }

            $slots = collect($response->json()['data']['slots'] ?? [])
                ->flatten(1)
                ->pluck('time')
                ->values()
                ->toArray();

            return ['slots' => $slots];
        } catch (\Throwable) {
            return ['slots' => []];
        }
    }

    public function cancel(string $eventId): bool
    {
        $apiKey = $this->credentials['api_key'] ?? null;
        if (!$apiKey) {
            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->delete(self::BASE_URL . "/bookings/{$eventId}");
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
