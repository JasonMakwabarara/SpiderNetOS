<?php

namespace App\Http\Middleware;

use App\Services\Integrations\DodoPaymentsAdapter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyDodoSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local', 'testing') && config('dodo.skip_signature_verify')) {
            return $next($request);
        }

        try {
            // Symfony's HeaderBag::all() returns string[][] (one array per
            // header name); flatten to the first value for the adapter's
            // simple string-map signature.
            $headers = array_map(fn (array $values) => $values[0] ?? '', $request->headers->all());

            // services.dodo is primary; fall back to the trunk-era config/dodo.php
            // keys so both configuration styles verify.
            $cfg = (array) config('services.dodo');
            $cfg['webhook_secret'] = $cfg['webhook_secret'] ?: config('dodo.webhook_secret');
            $cfg['api_key'] = $cfg['api_key'] ?: config('dodo.api_key');
            $cfg['environment'] = $cfg['environment'] ?? (config('dodo.mode') === 'live' ? 'live' : 'test');

            $adapter = new DodoPaymentsAdapter($cfg);
            $valid = $adapter->verifyWebhook($request->getContent(), $headers);
        } catch (\Throwable $e) {
            Log::critical('Dodo webhook verification errored', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook verification unavailable.'], 503);
        }

        if (! $valid) {
            Log::warning('dodo.signature_rejected', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
