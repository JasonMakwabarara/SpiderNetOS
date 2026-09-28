<?php

declare(strict_types=1);

namespace App\Http\Controllers\Outreach;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Outreach\Channels\LinkedInSafetyGovernor;
use App\Services\Outreach\Channels\LinkedInToSGate;
use App\Services\Outreach\Channels\ManualLinkedInChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LinkedIn outreach settings (plan D7 §3).
 *
 *   GET /api/sales/partners/linkedin/settings   mode, the acknowledgement, what the governor allows
 *   PUT /api/sales/partners/linkedin/settings   change the mode (admin; assisted needs the ack)
 *
 * The GET returns the acknowledgement text as well as its state, so the
 * screen shows what is being agreed to rather than a checkbox next to a link.
 */
class LinkedInSettingsController extends Controller
{
    public function show(Request $request, LinkedInToSGate $gate, LinkedInSafetyGovernor $governor): JsonResponse
    {
        $tenant = $this->tenant($request);

        return response()->json(['data' => $gate->settings($tenant) + [
            'channel' => ['key' => (new ManualLinkedInChannel)->key(), 'label' => (new ManualLinkedInChannel)->label(), 'can_send' => false],
            'limits' => [
                'connect_chars' => ManualLinkedInChannel::MAX_CONNECT_CHARS,
                'message_chars' => ManualLinkedInChannel::MAX_MESSAGE_CHARS,
                'connects_per_week' => LinkedInSafetyGovernor::MAX_CONNECTS_PER_WEEK,
                'actions_per_day' => LinkedInSafetyGovernor::MAX_ACTIONS_PER_DAY,
            ],
            'governor' => $governor->check($tenant),
        ]]);
    }

    public function update(Request $request, LinkedInToSGate $gate): JsonResponse
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', LinkedInToSGate::MODES)],
            'acknowledge_terms' => ['sometimes', 'boolean'],
            'sender_account_id' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            $settings = $gate->update(
                $tenant,
                $request->user(),
                $validated['mode'],
                (bool) ($validated['acknowledge_terms'] ?? false),
                $validated['sender_account_id'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'tos_not_acknowledged'], 422);
        }

        return response()->json(['data' => $settings]);
    }

    private function tenant(Request $request): Tenant
    {
        return Tenant::findOrFail((string) $request->attributes->get('tenant_id'));
    }
}
