<?php

namespace App\Http\Middleware;

use App\Models\VoiceNumber;
use App\Services\FeatureFlag;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * VoiceFeatureFlag — Phase A
 *
 * Global and per-tenant kill-switch for the voice vertical.
 *
 * Checks (in order):
 *   1. feature:voice.inbound  (global)
 *   2. feature:voice.inbound:tenant:<tenantId>  (per-tenant, when resolvable)
 *
 * When the flag is OFF, returns a static "service unavailable" TwiML so
 * Twilio gets a graceful response rather than an HTTP error.
 *
 * Kill-switch commands:
 *   php artisan feature:set voice.inbound off          # global
 *   php artisan feature:set voice.inbound off <tenantId>  # per-tenant
 */
class VoiceFeatureFlag
{
    public function handle(Request $request, Closure $next): Response
    {
        // Attempt per-tenant check first when tenant is resolvable from phone number
        $tenantId = $this->resolveTenantId($request);

        $flagOn = FeatureFlag::on('voice.inbound', $tenantId);

        if (! $flagOn) {
            Log::info('voice.feature_flag_blocked', [
                'tenant_id' => $tenantId,
                'path' => $request->path(),
            ]);

            $twiml = $this->unavailableTwiML();

            return response($twiml, 200, ['Content-Type' => 'application/xml']);
        }

        return $next($request);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Attempt to resolve a tenant_id from the `To` Twilio parameter so
     * per-tenant flag checks can short-circuit before DB lookup in the
     * controller. Returns null if not resolvable (falls back to global flag).
     */
    private function resolveTenantId(Request $request): ?string
    {
        $toNumber = $request->input('To');
        if (! $toNumber) {
            return null;
        }

        try {
            $voiceNumber = VoiceNumber::where('phone_number', $this->normalize($toNumber))
                ->where('is_active', true)
                ->select('tenant_id')
                ->first();

            return $voiceNumber?->tenant_id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalize(string $number): string
    {
        $n = preg_replace('/[^0-9+]/', '', $number);
        if (! str_starts_with($n, '+') && strlen($n) === 10) {
            $n = '+1'.$n;
        }

        return $n;
    }

    private function unavailableTwiML(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say voice="Polly.Joanna">Thank you for calling. Our voice service is temporarily unavailable. Please try again later or contact support.</Say>
    <Hangup/>
</Response>
XML;
    }
}
