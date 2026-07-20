<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MessagingNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessagingController extends Controller
{
    /** Channels the platform can send on today. */
    private const AVAILABLE = ['email', 'whatsapp', 'sms'];

    /**
     * GET /api/messaging/channels — the tenant's provisioned numbers by channel
     * plus the set of channels the platform supports.
     */
    public function channels(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $numbers = MessagingNumber::forTenant($tenantId)
            ->get(['id', 'channel', 'phone_number', 'provider', 'is_active']);

        return response()->json(['data' => [
            'available_channels' => self::AVAILABLE,
            'numbers' => $numbers,
        ]]);
    }
}
