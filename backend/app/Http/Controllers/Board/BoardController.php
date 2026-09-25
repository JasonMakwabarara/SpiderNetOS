<?php

declare(strict_types=1);

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\BoardSession as SessionModel;
use App\Services\Board\AdvisorRegistry;
use App\Services\Board\BoardSession;
use App\Services\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The board of advisors (plan D6 §6).
 *
 *   GET  /api/board/seats                 who sits, for this tenant
 *   GET  /api/board/sessions              past sessions, newest first
 *   POST /api/board/sessions              put a question to the board
 *   GET  /api/board/sessions/{id}         the session, its takes and its verdict
 *   GET  /api/board/sessions/{id}/report.md  the filed report as markdown
 *
 * Everything here is gated by `board.enabled`, and every response carries the
 * disclaimer: a confident archetype is still not a regulated adviser.
 */
class BoardController extends Controller
{
    public function seats(Request $request, AdvisorRegistry $advisors): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $seats = array_map(fn (array $seat): array => [
            'slug' => $seat['slug'],
            'display_name' => $seat['display_name'],
            'seat' => $seat['seat'],
            'archetype' => $seat['archetype'],
            'likeness_mode' => $seat['likeness_mode'],
            'inspired_by' => $seat['inspired_by'],
            'voice_persona' => $seat['voice_persona'],
            'brain_scopes' => $seat['brain_scopes'],
            'asks_first' => $seat['question_style'],
        ], $advisors->seatsFor($tenantId));

        $chair = $advisors->chair($tenantId);

        return response()->json([
            'data' => array_values($seats),
            'meta' => [
                'chair' => $chair === null ? null : ['slug' => $chair['slug'], 'display_name' => $chair['display_name']],
                'disclaimer' => config('board.disclaimer'),
                'private_roster' => FeatureFlag::on('board.private_roster', $tenantId),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $sessions = SessionModel::forTenant($tenantId)
            ->with('verdict:id,session_id,consensus,recommended_action,next_check_date,minority_seat')
            ->orderByDesc('created_at')
            ->limit(min(100, max(1, (int) $request->input('limit', 25))))
            ->get(['id', 'slug', 'question', 'status', 'round', 'cost_usd', 'brain_path', 'completed_at', 'created_at']);

        return response()->json(['data' => $sessions, 'meta' => ['disclaimer' => config('board.disclaimer')]]);
    }

    public function store(Request $request, BoardSession $board): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:12', 'max:2000'],
            'seats' => ['sometimes', 'array', 'max:8'],
            'seats.*' => ['string', 'max:64'],
        ]);

        $session = $board->convene(
            $tenantId,
            $validated['question'],
            (string) ($request->user()?->id ?? ''),
            $validated['seats'] ?? null,
        );

        return response()->json([
            'data' => $this->payload($session),
            'meta' => ['disclaimer' => config('board.disclaimer')],
        ], $session->status === 'failed' ? 502 : 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $session = $this->find($tenantId, $id);
        if ($session === null) {
            return response()->json(['message' => 'No such board session.'], 404);
        }

        return response()->json([
            'data' => $this->payload($session, withTakes: true),
            'meta' => ['disclaimer' => config('board.disclaimer')],
        ]);
    }

    public function report(Request $request, string $id): Response|JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $session = $this->find($tenantId, $id);
        if ($session === null || $session->verdict === null) {
            return response()->json(['message' => 'No report for that session.'], 404);
        }

        return response(BoardSession::markdown($session, $session->verdict), 200)
            ->header('Content-Type', 'text/markdown; charset=utf-8');
    }

    // ------------------------------------------------------------------ //

    /** Accepts an id or the session slug, so a report URL can be readable. */
    private function find(string $tenantId, string $id): ?SessionModel
    {
        return SessionModel::forTenant($tenantId)
            ->with(['verdict', 'takes'])
            ->where(fn ($q) => $q->where('slug', $id)->when(Str::isUuid($id), fn ($q2) => $q2->orWhere('id', $id)))
            ->first();
    }

    private function gate(string $tenantId): ?JsonResponse
    {
        if (FeatureFlag::on('board.enabled', $tenantId)) {
            return null;
        }

        return response()->json([
            'message' => 'The board of advisors is not switched on for this workspace.',
            'reason' => 'board.disabled',
        ], 403);
    }

    private function payload(SessionModel $session, bool $withTakes = false): array
    {
        $data = [
            'id' => $session->id,
            'slug' => $session->slug,
            'question' => $session->question,
            'status' => $session->status,
            'seats' => $session->seats,
            'missing_brain' => (array) ($session->brief['missing'] ?? []),
            'cost_usd' => (float) $session->cost_usd,
            'brain_path' => $session->brain_path,
            'error' => $session->error,
            'completed_at' => $session->completed_at,
            'verdict' => $session->verdict,
        ];

        if ($withTakes) {
            // Round 1 is the honest record of who thought what before anyone
            // argued; it is shown with names because the anonymisation exists
            // to protect the *seats* from each other, not the founder from
            // the seats.
            $data['takes'] = $session->takes->map(fn ($take): array => [
                'seat' => $take->seat,
                'round' => $take->round,
                'verdict' => $take->verdict,
                'error' => $take->error,
            ])->values();
        }

        return $data;
    }
}
