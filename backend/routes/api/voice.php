<?php

declare(strict_types=1);

use App\Http\Controllers\Voice\AtlasVoiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Atlas voice — /api/voice/personas, /api/me/voice, /api/atlas/speak (plan D7 §7)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the protected group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api).
| Telephony keeps /api/voice/{call,calls,numbers,quotas} in routes/api.php.
|
|   GET  /voice/personas                  active personas, default first (+ resolution meta)
|   GET  /voice/personas/{slug}/preview   rendered sample (?line=greeting|verdict|apology)
|   GET  /me/voice                        {persona_slug, speak_enabled, browser_fallback, resolved_persona}
|   PUT  /me/voice                        users.voice_persona_slug + preferences.atlas_speak|atlas_browser_fallback
|   PUT  /admin/tenant/voice-default      tenants.settings.voice.default_persona (role:admin)
|   POST /atlas/speak                     {text | message_id, persona_slug?} → audio (flag voice.atlas_speak)
*/

Route::get('/voice/personas', [AtlasVoiceController::class, 'personas'])->name('voice.personas.index');
Route::get('/voice/personas/{slug}/preview', [AtlasVoiceController::class, 'preview'])
    ->where('slug', AtlasVoiceController::SLUG_PATTERN)
    ->name('voice.personas.preview');

Route::get('/me/voice', [AtlasVoiceController::class, 'showMe'])->name('me.voice.show');
Route::put('/me/voice', [AtlasVoiceController::class, 'updateMe'])->name('me.voice.update');

Route::put('/admin/tenant/voice-default', [AtlasVoiceController::class, 'updateTenantDefault'])
    ->middleware('role:admin')
    ->name('admin.tenant.voice-default');

Route::post('/atlas/speak', [AtlasVoiceController::class, 'speak'])
    ->middleware('throttle:atlas_chat')
    ->name('atlas.speak');
