<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\NewsletterIssue;
use App\Services\Reports\CustomerNewsletterCadence;
use App\Services\Reports\MondayLetterComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The Monday letter and the C-Suite newsletter inside it (plan D8 #11, #15).
 *
 *   GET /api/reports/weekly            the letter for the week just ended
 *   GET /api/reports/weekly/{period}   a specific ISO week, e.g. 2026-W38
 *   GET /api/reports/newsletters       the filed issues, newest first
 *
 * Composing is cheap and deterministic, so a past week is recomposed from the
 * ledger rather than served from a cache that could drift from it.
 */
class WeeklyLetterController extends Controller
{
    private const PERIOD_PATTERN = '/^(\d{4})-W(\d{2})$/';

    public function show(Request $request, MondayLetterComposer $composer, ?string $period = null): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $for = null;
        if ($period !== null) {
            $for = self::weekStart($period);
            if ($for === null) {
                return response()->json(['message' => 'Expected an ISO week like 2026-W38.', 'reason' => 'bad_period'], 422);
            }
        }

        $letter = $composer->compose($tenantId, $for);

        if (! $request->boolean('markdown', true)) {
            unset($letter['markdown'], $letter['csuite']['markdown']);
        }

        return response()->json(['data' => $letter]);
    }

    public function index(Request $request, CustomerNewsletterCadence $cadence): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $kind = (string) $request->input('kind', '');
        $issues = NewsletterIssue::forTenant($tenantId)
            ->when(in_array($kind, NewsletterIssue::KINDS, true), fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('created_at')
            ->limit(min(100, max(1, (int) $request->input('limit', 26))))
            ->get(['id', 'kind', 'period', 'status', 'subject', 'quote_id', 'brain_path', 'channel', 'recipients', 'sent_at', 'created_at']);

        return response()->json([
            'data' => $issues,
            'meta' => [
                // When the next customer issues are due, so the cockpit can say
                // "next issue Monday" instead of leaving the cadence invisible.
                'customer_cadence_days' => CustomerNewsletterCadence::INTERVAL_DAYS,
                'customer_upcoming' => $cadence->upcoming($tenantId, now(), 4),
            ],
        ]);
    }

    /** "2026-W38" → the Monday of that ISO week, or null when it is not one. */
    public static function weekStart(string $period): ?Carbon
    {
        if (! preg_match(self::PERIOD_PATTERN, $period, $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $week = (int) $matches[2];
        if ($week < 1 || $week > 53) {
            return null;
        }

        $date = Carbon::now()->setISODate($year, $week)->startOfWeek();

        // setISODate happily rolls week 53 into the next year; reject that
        // rather than silently answering for a different week.
        return $date->format('o-\WW') === $period ? $date : null;
    }
}
