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
        if (app()->environment('local', 'testing') && filter_var(env('DODO_SKIP_SIGNATURE_VERIFY', false), FILTER_VALIDATE_BOOLEAN)) {
            return $next($request);
        }

        try {
            // Symfony's HeaderBag::all() returns string[][] (one array per
            // header name); flatten to the first value for the adapter's
            // simple string-map signature.
            $headers = array_map(fn (array $values) => $values[0] ?? '', $request->headers->all());

            $adapter = new DodoPaymentsAdapter((array) config('services.dodo'));
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
