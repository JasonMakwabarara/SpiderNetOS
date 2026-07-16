<?php

declare(strict_types=1);

namespace App\Services\Integrations;

/**
 * CalendarAdapter — Phase D
 *
 * Abstract base for calendar provider adapters.
 * Concrete implementations: GoogleCalendarAdapter, CalComAdapter.
 *
 * All adapters implement the same contract so VoiceTools and the
 * CalendarController can switch providers via config.
 */
abstract class CalendarAdapter
{
    public function __construct(
        protected readonly string $tenantId,
        protected readonly array  $credentials,
    ) {}

    /**
     * Book an appointment.
     *
     * @param  array{
     *   date: string,          YYYY-MM-DD
     *   time: string,          HH:MM (24h)
     *   duration_minutes: int,
     *   attendee_name: string,
     *   attendee_phone: string,
     *   attendee_email: string|null,
     *   description: string|null,
     *   calendar_id: string|null,
     * } $event
     * @return array{success: bool, event_id: string|null, calendar_link: string|null, error: string|null}
     */
    abstract public function book(array $event): array;

    /**
     * Check availability for a given date.
     *
     * @return array{slots: list<string>}  ISO datetime strings
     */
    abstract public function getAvailableSlots(string $date, ?string $calendarId = null): array;

    /**
     * Cancel an existing event.
     */
    abstract public function cancel(string $eventId): bool;

    // ─── Factory ──────────────────────────────────────────────────────────────

    /**
     * Create the appropriate adapter for a tenant based on their integration record.
     */
    public static function make(string $tenantId, string $provider, array $credentials): static
    {
        return match ($provider) {
            'google_calendar' => new GoogleCalendarAdapter($tenantId, $credentials),
            'cal_com'         => new CalComAdapter($tenantId, $credentials),
            default           => throw new \InvalidArgumentException("Unknown calendar provider: {$provider}"),
        };
    }
}
