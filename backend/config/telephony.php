<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Telephony Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "twilio", "signalwire", "vonage"
    |
    */
    'default' => env('TELEPHONY_PROVIDER', 'twilio'),

    /*
    |--------------------------------------------------------------------------
    | Provider Credentials
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'twilio' => [
            'sid' => env('TWILIO_SID', ''),
            'auth_token' => env('TWILIO_AUTH_TOKEN', ''),
            'api_base' => 'https://api.twilio.com/2010-04-01',
            'webhook_url' => env('TWILIO_WEBHOOK_URL', 'https://your-domain.com/voice/inbound'),
            'status_callback' => env('TWILIO_STATUS_CALLBACK', 'https://your-domain.com/voice/status'),
        ],
        'signalwire' => [
            'project_id' => env('SIGNALWIRE_PROJECT_ID', ''),
            'auth_token' => env('SIGNALWIRE_AUTH_TOKEN', ''),
            'space' => env('SIGNALWIRE_SPACE', ''),  // e.g. example.signalwire.com
            'api_base' => env('SIGNALWIRE_API_BASE', ''),  // computed if empty
            'webhook_url' => env('SIGNALWIRE_WEBHOOK_URL', 'https://your-domain.com/voice/inbound'),
            'status_callback' => env('SIGNALWIRE_STATUS_CALLBACK', 'https://your-domain.com/voice/status'),
        ],
        'vonage' => [
            'api_key' => env('VONAGE_API_KEY', ''),
            'api_secret' => env('VONAGE_API_SECRET', ''),
            'application_id' => env('VONAGE_APPLICATION_ID', ''),
            'private_key' => env('VONAGE_PRIVATE_KEY', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Speech-to-Text Configuration
    |--------------------------------------------------------------------------
    |
    | Provider: "twilio" (built-in), "whisper" (local), "deepgram" (cloud)
    |
    */
    'stt' => [
        'provider' => env('VOICE_STT_PROVIDER', 'twilio'),
        'language' => env('VOICE_STT_LANGUAGE', 'en-US'),
        'deepgram' => [
            'api_key' => env('DEEPGRAM_API_KEY', ''),
            'model' => 'nova-2',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Text-to-Speech Configuration
    |--------------------------------------------------------------------------
    |
    | Provider: "twilio" (built-in), "piper" (local), "elevenlabs" (cloud)
    |
    */
    'tts' => [
        'provider' => env('VOICE_TTS_PROVIDER', 'twilio'),
        'voice' => env('VOICE_TTS_VOICE', 'Polly.Joanna'),
        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY', ''),
            'voice_id' => env('ELEVENLABS_VOICE_ID', '21m00Tcm4TlvDq8ikWAM'),
            'model' => 'eleven_flash_v2_5', // low latency
        ],
        'piper' => [
            'url' => env('PIPER_URL', 'http://inference:9000/tts'),
            'voice_model' => 'en_US-lessac-medium',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Voice Agent Defaults
    |--------------------------------------------------------------------------
    */
    'agent' => [
        'default_agent_id' => 'voice_receptionist',
        'max_call_duration' => 600, // 10 minutes
        'gather_timeout' => 5, // seconds to wait for speech
        'gather_speech_timeout' => 'auto', // or seconds
        'interruptions_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming Mode (Advanced)
    |--------------------------------------------------------------------------
    |
    | WebSocket media streaming for real-time processing
    |
    */
    'streaming' => [
        'enabled' => env('VOICE_STREAMING_ENABLED', false),
        'websocket_url' => env('VOICE_STREAMING_WS_URL', 'wss://your-domain.com/voice/stream'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-Call Processing
    |--------------------------------------------------------------------------
    */
    'post_call' => [
        'summarize_enabled' => true,
        'email_summary' => true,
        'crm_sync' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Kill-Switch and Safety (Phase A)
    |--------------------------------------------------------------------------
    */
    'kill_switch' => env('VOICE_KILL_SWITCH', false),

    // Maximum simultaneous inbound calls per tenant (0 = unlimited)
    'per_tenant_max_concurrent' => env('VOICE_MAX_CONCURRENT_PER_TENANT', 10),

    // Daily cap in minutes across all numbers for a tenant (0 = unlimited)
    'per_tenant_daily_cap_seconds' => env('VOICE_DAILY_CAP_SECONDS', 0),

    // Whether outbound calls require an ApprovalEngine approval
    'require_approval_for_outbound' => env('VOICE_REQUIRE_APPROVAL_OUTBOUND', false),

    /*
    |--------------------------------------------------------------------------
    | Streaming Mode — Phase C
    |--------------------------------------------------------------------------
    */
    'streaming' => [
        'enabled' => env('VOICE_STREAMING_ENABLED', false),
        'websocket_url' => env('VOICE_STREAMING_WS_URL', 'wss://your-domain.com/voice/stream'),
        // STT provider to use in streaming mode (deepgram preferred for real-time)
        'stt_provider' => env('VOICE_STREAMING_STT_PROVIDER', 'deepgram'),
        // TTS provider to use in streaming mode (elevenlabs for low latency)
        'tts_provider' => env('VOICE_STREAMING_TTS_PROVIDER', 'elevenlabs'),
        // Voice activity detection threshold [0.0–1.0]
        'vad_threshold' => (float) env('VOICE_VAD_THRESHOLD', 0.6),
        // Maximum ms to wait for barge-in cut-off
        'barge_in_cutoff_ms' => (int) env('VOICE_BARGE_IN_CUTOFF_MS', 250),
    ],
];
