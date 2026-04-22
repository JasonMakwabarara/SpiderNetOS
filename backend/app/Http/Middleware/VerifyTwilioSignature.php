<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyTwilioSignature — Phase A
 *
 * Validates the X-Twilio-Signature HMAC-SHA1 header on every incoming
 * webhook request from Twilio.
 *
 * Reference: https://www.twilio.com/docs/usage/security#validating-signatures-from-twilio
 *
 * Bypass behaviour:
 *   - APP_ENV=local  OR  TWILIO_SKIP_SIGNATURE_VERIFY=true → bypass (dev / CI)
 *   - Missing auth token → log warning, bypass ONLY in local; block in production
 */
class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        // Allow bypass in local / test environments
        if ($this->shouldBypass()) {
            return $next($request);
        }

        $authToken = config('telephony.providers.twilio.auth_token');

        if (empty($authToken)) {
            Log::critical('Twilio auth token not configured — cannot verify webhook signature');
            return $this->reject('Twilio auth token not configured');
        }

        $signature = $request->header('X-Twilio-Signature', '');

        if (empty($signature)) {
            Log::warning('voice.signature_missing', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
            ]);
            $this->incrementRejectionCounter();
            return $this->reject('Missing Twilio signature');
        }

        $url  = $this->buildCanonicalUrl($request);
        $body = $this->buildSignatureBody($request);

        $expected = base64_encode(hash_hmac('sha1', $body, $authToken, true));

        if (!hash_equals($expected, $signature)) {
            Log::warning('voice.signature_rejected', [
                'ip'       => $request->ip(),
                'path'     => $request->path(),
                'expected' => substr($expected, 0, 8) . '…',
            ]);
            $this->incrementRejectionCounter();
            return $this->reject('Invalid Twilio signature');
        }

        return $next($request);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function shouldBypass(): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }
        return filter_var(env('TWILIO_SKIP_SIGNATURE_VERIFY', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Build the canonical URL string Twilio uses when computing its signature.
     * Twilio appends sorted POST params to the URL for application/x-www-form-urlencoded.
     */
    private function buildCanonicalUrl(Request $request): string
    {
        return $request->url();
    }

    /**
     * Build the string-to-sign: URL + sorted POST key=value pairs (no separator).
     */
    private function buildSignatureBody(Request $request): string
    {
        $url = $this->buildCanonicalUrl($request);

        if ($request->isMethod('POST') &&
            str_contains($request->header('Content-Type', ''), 'application/x-www-form-urlencoded')) {
            $params = $request->post();
            ksort($params);
            foreach ($params as $key => $value) {
                $url .= $key . $value;
            }
        }

        return $url;
    }

    private function reject(string $reason): Response
    {
        // Return minimal TwiML so Twilio doesn't retry forever
        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';

        return response($twiml, 403, ['Content-Type' => 'application/xml']);
    }

    private function incrementRejectionCounter(): void
    {
        try {
            \Illuminate\Support\Facades\Redis::incr('metrics:voice:signature_rejected_total');
        } catch (\Throwable) {
            // non-critical
        }
    }
}
