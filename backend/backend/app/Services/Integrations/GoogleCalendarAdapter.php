<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GoogleCalendarAdapter — Phase D
 *
 * Integrates with Google Calendar API v3.
 * Credentials: OAuth2 access token (stored encrypted in tenant_secrets,
 * referenced via tenant_integrations.credentials_ref).
 */
class GoogleCalendarAdapter extends CalendarAdapter
{
    private const BASE_URL = 'https://www.googleapis.com/calendar/v3';

    public function book(array $event): array
    {
        $token      = $this->credentials['access_token'] ?? null;
        $calendarId = $event['calendar_id'] ?? 'primary';

        if (!$token) {
            return ['success' => false, 'event_id' => null, 'calendar_link' => null, 'error' => 'Missing OAuth token'];
        }

        try {
            $startDatetime = $event['date'] . 'T' . $event['time'] . ':00';
            $endDatetime   = date(
                'Y-m-d\TH:i:s',
                strtotime($startDatetime) + ($event['duration_minutes'] ?? 30) * 60
            );

            $body = [
                'summary'     => $event['description'] ?? 'Call-in Appointment',
                'description' => "Booked via SpiderNet VoiceAgent for {$event['attendee_name']}",
                'start'       => ['dateTime' => $startDatetime, 'timeZone' => 'America/New_York'],
                'end'         => ['dateTime' => $endDatetime,   'timeZone' => 'America/New_York'],
                'attendees'   => array_filter([
                    $event['attendee_email'] ? ['email' => $event['attendee_email']] : null,
                ]),
            ];

            $response = Http::withToken($token)
                ->post(self::BASE_URL . "/calendars/{$calendarId}/events", $body);

            if (!$response->successful()) {
                Log::warning('google_calendar.book_failed', [
                    'tenant_id' => $this->tenantId,
                    'status'    => $response->status(),
                    'body'      => $response->body(),
                ]);
                return ['success' => false, 'event_id' => null, 'calendar_link' => null, 'error' => 'API error: ' . $response->status()];
            }

            $data = $response->json();

            return [
                'success'       => true,
                'event_id'      => $data['id'],
                'calendar_link' => $data['htmlLink'] ?? null,
                'error'         => null,
            ];
        } catch (\Throwable $e) {
            Log::error('google_calendar.book_exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'event_id' => null, 'calendar_link' => null, 'error' => $e->getMessage()];
        }
    }

    public function getAvailableSlots(string $date, ?string $calendarId = null): array
    {
        $token      = $this->credentials['access_token'] ?? null;
        $calendarId = $calendarId ?? 'primary';

        if (!$token) {
            return ['slots' => []];
        }

        // Use freebusy API to determine available 30-minute slots
        try {
            $timeMin = $date . 'T09:00:00Z';
            $timeMax = $date . 'T17:00:00Z';

            $response = Http::withToken($token)->post(self::BASE_URL . '/freeBusy', [
                'timeMin'  => $timeMin,
                'timeMax'  => $timeMax,
                'items'    => [['id' => $calendarId]],
            ]);

            if (!$response->successful()) {
                return ['slots' => []];
            }

            $busy   = $response->json()['calendars'][$calendarId]['busy'] ?? [];
            $slots  = $this->computeFreeSlots($timeMin, $timeMax, $busy);

            return ['slots' => $slots];
        } catch (\Throwable) {
            return ['slots' => []];
        }
    }

    public function cancel(string $eventId): bool
    {
        $token      = $this->credentials['access_token'] ?? null;
        $calendarId = 'primary';

        if (!$token) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->delete(self::BASE_URL . "/calendars/{$calendarId}/events/{$eventId}");
            return $response->successful() || $response->status() === 204;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function computeFreeSlots(string $timeMin, string $timeMax, array $busy): array
    {
        $start     = strtotime($timeMin);
        $end       = strtotime($timeMax);
        $slotMins  = 30;
        $slotSecs  = $slotMins * 60;
        $slots     = [];

        $busyPeriods = array_map(
            fn($b) => [strtotime($b['start']), strtotime($b['end'])],
            $busy
        );

        for ($t = $start; $t + $slotSecs <= $end; $t += $slotSecs) {
            $conflict = false;
            foreach ($busyPeriods as [$bStart, $bEnd]) {
                if ($t < $bEnd && ($t + $slotSecs) > $bStart) {
                    $conflict = true;
                    break;
                }
            }
            if (!$conflict) {
                $slots[] = date('c', $t);
            }
        }

        return $slots;
    }
}
