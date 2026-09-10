<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TenantIntegration;
use App\Services\Connectors\ConnectorManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Affonso webhook signature: `X-Affonso-Signature: t=<unix>,v1=<hex>` where
 * v1 = HMAC-SHA256("{t}.{raw body}", endpoint secret), rejected when the
 * timestamp is more than five minutes off. The secret is the tenant's affonso
 * connector `webhook_secret`, so every tenant verifies with its own key.
 */
class VerifyAffonsoSignature
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly ConnectorManager $connectors) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (string) $request->route('tenant');
        if (! Str::isUuid($tenantId)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $integration = TenantIntegration::forTenant($tenantId)->where('provider', 'affonso')->where('is_active', true)->first();
        if ($integration === null) {
            // Deliberately generic: never confirm which tenants exist.
            return response()->json(['message' => 'Not found.'], 404);
        }

        $secret = trim((string) ($this->connectors->credentialsFor($integration)['webhook_secret'] ?? ''));
        if ($secret === '') {
            Log::warning('affonso.webhook.no_secret', ['tenant_id' => $tenantId]);

            return response()->json(['message' => 'Webhook secret not configured.'], 401);
        }

        if (! self::verify((string) $request->header('X-Affonso-Signature', ''), $request->getContent(), $secret, now()->getTimestamp())) {
            Log::warning('affonso.signature_rejected', ['tenant_id' => $tenantId, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }

    public static function verify(string $header, string $body, string $secret, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            $key = strtolower((string) $key);
            if ($key === 't' && $value !== null && ctype_digit(trim($value))) {
                $timestamp = (int) trim($value);
            } elseif ($key === 'v1' && $value !== null && trim($value) !== '') {
                $signatures[] = strtolower(trim($value));
            }
        }

        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Build a header the way Affonso does (used by tests and the connector "Test" button). */
    public static function sign(string $body, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
