<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenAI\Client;

class RealtimeController extends Controller
{
    public function createSession(Request $request)
    {
        $request->validate([
            'model' => 'required|string|in:gpt-4o-realtime',
        ]);

        $tenant = $request->attributes->get('tenant');
        $user = $request->user();

        // Create session with OpenAI
        $client = new Client(config('services.openai.api_key'));
        $session = $client->realtime()->createSession([
            'model' => $request->model,
            'modalities' => ['text', 'audio'],
        ]);

        // Store in DB
        \DB::table('realtime_sessions')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'status' => 'active',
            'created_at' => now(),
        ]);

        return response()->json([
            'session_id' => $session->id,
            'websocket_url' => $session->websocket_url,
            'token' => $session->token,
        ]);
    }
}