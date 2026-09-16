<?php

declare(strict_types=1);

namespace App\Http\Controllers\Atlas;

use App\Http\Controllers\Controller;
use App\Models\AtlasThread;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Atlas context threads (plan D8 #13) — the /api/atlas/sessions routes that
 * cockpit/src/stores/atlas.js already calls. A thread is the durable
 * session: title, business, pinned brain paths, spawned runs, open
 * questions, last summary / next steps and the one-more-question state.
 * Messages are hydrated from the event_log (aggregate atlas_session) so the
 * chat endpoint keeps Hard Rule #3 (no shared runtime memory).
 */
class AtlasThreadController extends Controller
{
    /** GET /api/atlas/sessions — latest first. */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $query = AtlasThread::forTenant($tenantId)->latestFirst();

        if ($request->boolean('mine')) {
            $query->where('user_id', (string) $request->user()->id);
        }
        if (is_string($request->input('business')) && $request->input('business') !== '') {
            $query->where('business', $request->input('business'));
        }

        $limit = max(1, min(100, (int) $request->input('limit', 25)));

        return response()->json([
            'data' => $query->limit($limit)->get()->map(fn (AtlasThread $t): array => $t->toEnvelope())->values(),
        ]);
    }

    /**
     * POST /api/atlas/sessions — create a thread (or return an existing one
     * when thread_id is given). Returns {data: {id, title, …, messages, suggestions}}.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $v = $request->validate([
            'thread_id' => 'sometimes|nullable|string|uuid',
            'title' => 'sometimes|nullable|string|max:255',
            'business' => 'sometimes|nullable|string|max:120',
            'pinned_brain_paths' => 'sometimes|array|max:50',
            'pinned_brain_paths.*' => 'string|max:255',
        ]);

        if (! empty($v['thread_id'])) {
            $existing = AtlasThread::forTenant($tenantId)->find($v['thread_id']);
            if ($existing !== null) {
                $existing->last_seen_at = now();
                $existing->save();

                return response()->json(['data' => $this->envelope($existing)]);
            }
        }

        $thread = AtlasThread::create([
            'tenant_id' => $tenantId,
            'user_id' => (string) $request->user()->id,
            'title' => isset($v['title']) && trim((string) $v['title']) !== '' ? trim((string) $v['title']) : null,
            'business' => $v['business'] ?? null,
            'pinned_brain_paths' => array_values(array_unique((array) ($v['pinned_brain_paths'] ?? []))),
            'last_seen_at' => now(),
        ]);

        return response()->json(['data' => $this->envelope($thread)], 201);
    }

    /** GET /api/atlas/sessions/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $thread = $this->find($request, $id);
        if ($thread === null) {
            return response()->json(['message' => 'Thread not found.'], 404);
        }

        $thread->last_seen_at = now();
        $thread->save();

        return response()->json(['data' => $this->envelope($thread)]);
    }

    /** PATCH /api/atlas/sessions/{id} — title, business, pinned brain paths. */
    public function update(Request $request, string $id): JsonResponse
    {
        $thread = $this->find($request, $id);
        if ($thread === null) {
            return response()->json(['message' => 'Thread not found.'], 404);
        }

        $v = $request->validate([
            'title' => 'sometimes|nullable|string|max:255',
            'business' => 'sometimes|nullable|string|max:120',
            'pinned_brain_paths' => 'sometimes|array|max:50',
            'pinned_brain_paths.*' => 'string|max:255',
            'last_summary' => 'sometimes|nullable|string|max:4000',
        ]);

        if (array_key_exists('title', $v)) {
            $thread->title = $v['title'] !== null && trim((string) $v['title']) !== '' ? trim((string) $v['title']) : null;
        }
        if (array_key_exists('business', $v)) {
            $thread->business = $v['business'];
        }
        if (array_key_exists('pinned_brain_paths', $v)) {
            $thread->pinned_brain_paths = array_values(array_unique((array) $v['pinned_brain_paths']));
        }
        if (array_key_exists('last_summary', $v)) {
            $thread->last_summary = $v['last_summary'];
        }
        $thread->last_seen_at = now();
        $thread->save();

        return response()->json(['data' => $this->envelope($thread->refresh())]);
    }

    private function find(Request $request, string $id): ?AtlasThread
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return AtlasThread::forTenant((string) $request->attributes->get('tenant_id'))->find($id);
    }

    /** @return array<string, mixed> */
    private function envelope(AtlasThread $thread): array
    {
        $data = $thread->toEnvelope();
        $data['messages'] = $this->messages($thread);
        $data['suggestions'] = array_values(array_filter(array_map(
            fn ($step) => is_array($step) && ! empty($step['label']) ? ['type' => 'next_step', 'label' => $step['label'], 'skill' => $step['skill'] ?? null, 'id' => $step['id'] ?? null] : null,
            (array) $thread->last_next_steps,
        )));

        return $data;
    }

    /**
     * Chat history from the event log (aggregate atlas_session = thread id).
     *
     * @return list<array<string, mixed>>
     */
    private function messages(AtlasThread $thread): array
    {
        if (! Schema::hasTable('event_log')) {
            return [];
        }

        try {
            return Event::forAggregate('atlas_session', (string) $thread->id)
                ->whereIn('event_type', ['atlas.message.received', 'atlas.message.sent'])
                ->limit(200)
                ->get()
                ->map(function (Event $e): array {
                    $payload = (array) ($e->payload ?? []);
                    $isUser = $e->event_type === 'atlas.message.received';

                    return [
                        'id' => (string) $e->id,
                        'role' => $isUser ? 'user' : 'atlas',
                        'content' => $isUser
                            ? (string) ($payload['content'] ?? '')
                            : (string) ($payload['contract']['action_summary'] ?? ''),
                        'contract' => $isUser ? null : ($payload['contract'] ?? null),
                        'interaction_id' => $payload['interaction_id'] ?? null,
                        'timestamp' => $e->occurred_at?->toIso8601String(),
                    ];
                })->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
