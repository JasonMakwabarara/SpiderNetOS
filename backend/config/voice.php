<?php

/*
|--------------------------------------------------------------------------
| Atlas voice (plan D7 §7)
|--------------------------------------------------------------------------
| ElevenLabs is the primary provider; Fish Audio and Intron are the
| alternatives; Piper is the local dev floor. Azure is dormant — Jason
| rejected the Azure voices (2026-09-16), so Azure personas are only seeded
| with `voice:sync-personas --include-azure`.
|
| Telephony voice (numbers, calls, quotas) lives in config/telephony.php.
| Flags: voice.atlas_speak (POST /api/atlas/speak) in config/features.php.
*/

return [

    // Persona slug used when neither the user (users.voice_persona_slug) nor the
    // tenant (tenants.settings.voice.default_persona) chose one. Unset → the first
    // active persona. The inference plane's own fallback is VOICE_DEFAULT_PERSONA too.
    'default_persona' => env('VOICE_DEFAULT_PERSONA'),

    // inference/voice_personas.yaml. Unset → {services.inference.path}/voice_personas.yaml,
    // then base_path('../inference/voice_personas.yaml').
    'personas_path' => env('VOICE_PERSONAS_PATH'),

    'speak' => [
        // POST /api/atlas/speak refuses longer text (a message_id is clipped to it).
        'max_chars' => 2000,
        // Rendered clips, keyed sha256(text|persona) per tenant.
        'cache_disk' => env('VOICE_SPEAK_CACHE_DISK', 'local'),
        'cache_prefix' => 'voice/atlas-speak',
        'cache_ttl_seconds' => 86400,
        'format' => 'mp3',
        'timeout' => (int) env('VOICE_SPEAK_TIMEOUT', 60),
    ],

    // Listening-page samples rendered by `voice:render-previews` into
    // storage/app/public/voice-previews/<slug>/<line>.{mp3,wav}.
    'previews' => [
        'disk' => 'public',
        'root' => 'voice-previews',
        'lines' => ['greeting', 'verdict', 'apology'],
    ],

];
