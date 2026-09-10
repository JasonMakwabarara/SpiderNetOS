<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Messaging\MessageDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly MessageDispatchService $dispatch,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $conversations = Conversation::forTenant($tenant->id)
            ->with('lead')
            ->orderByDesc('last_message_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json(['data' => $conversations]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $conversation = Conversation::forTenant($tenant->id)->with(['lead', 'messages'])->findOrFail($id);

        return response()->json(['data' => $conversation]);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $conversation = Conversation::forTenant($tenant->id)->with('lead')->findOrFail($id);

        $validated = $request->validate([
            'body' => 'required|string|max:4000',
        ]);

        $lead = $conversation->lead;
        if ($lead === null) {
            return response()->json(['message' => 'Conversation has no lead.'], 422);
        }

        $result = $this->dispatch->send($lead, $conversation->channel, $validated['body'], null, null, (string) $request->user()->id);

        if ($result['success']) {
            $conversation->update(['status' => 'open']);
        }

        return response()->json(['data' => $result], $result['success'] ? 200 : 422);
    }
}
