<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoicePersona;
use App\Services\Voice\AtlasSpeechException;
use App\Services\Voice\AtlasSpeechService;
use App\Services\Voice\VoicePersonaCatalogue;
use App\Services\Voice\VoicePersonaUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Atlas voice (plan D7 §7): the persona picker, the user's and the tenant's
 * choice, previews, and POST /api/atlas/speak. Telephony voice (numbers,
 * calls, quotas) stays in VoiceController.
 */
class AtlasVoiceController extends Controller
{
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(
        private readonly VoicePersonaCatalogue $catalogue,
        private readonly AtlasSpeechService $speech,
    ) {}

    /** GET /api/voice/personas — active personas Atlas may speak with, default first. */
    public function personas(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $resolution = $this->catalogue->resolve($user);

        return response()->json([
            'data' => $this->catalogue->active()->map(fn (VoicePersona $persona): array => $persona->toApi())->values(),
            'meta' => [
                'resolved_persona' => $resolution['persona']?->slug,
                'resolved_from' => $resolution['source'],
                'user_persona' => $user->getAttribute('voice_persona_slug'),
                'tenant_default' => data_get($this->tenant($user)?->settings, 'voice.default_persona'),
                'speak_available' => $this->speech->enabled((string) $user->tenant_id),
                'max_chars' => $this->speech->maxChars(),
                'preview_lines' => array_values((array) config('voice.previews.lines', [])),
            ],
        ]);
    }

    /**
     * GET /api/voice/personas/{slug}/preview?line=greeting|verdict|apology
     *
     * The sample `voice:render-previews` rendered for the persona, else a
     * redirect to the provider's public library preview, else 404.
     */
    public function preview(Request $request, string $slug): Response|JsonResponse|RedirectResponse
    {
        $persona = $this->catalogue->find($slug);
        if ($persona === null || $this->catalogue->refusal($persona) === VoicePersonaUnavailable::CONSENT_INCOMPLETE) {
            return response()->json(['message' => 'Voice not found.'], 404);
        }

        $lines = array_values((array) config('voice.previews.lines', ['greeting', 'verdict', 'apology']));
        $line = (string) $request->query('line', $lines[0] ?? 'greeting');
        if (! in_array($line, $lines, true)) {
            return response()->json(['message' => 'line must be one of: '.implode(', ', $lines).'.'], 422);
        }

        $disk = Storage::disk((string) config('voice.previews.disk', 'public'));
        $root = trim((string) config('voice.previews.root', 'voice-previews'), '/');
        foreach (['mp3' => 'audio/mpeg', 'wav' => 'audio/wav'] as $ext => $contentType) {
            $path = "{$root}/{$persona->slug}/{$line}.{$ext}";
            if ($disk->exists($path)) {
                return response((string) $disk->get($path), 200, [
                    'Content-Type' => $contentType,
                    'Cache-Control' => 'private, max-age=3600',
                ]);
            }
        }

        if (is_string($persona->preview_url) && str_starts_with(strtolower($persona->preview_url), 'https://')) {
            return redirect()->away($persona->preview_url);
        }

        return response()->json(['message' => 'No preview has been rendered for this voice yet (php artisan voice:render-previews).'], 404);
    }

    /** GET /api/me/voice */
    public function showMe(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->mePayload($request->user())]);
    }

    /**
     * PUT /api/me/voice {persona_slug?, speak_enabled?, browser_fallback?}
     * Writes users.voice_persona_slug and users.preferences.atlas_speak /
     * atlas_browser_fallback (other preferences are kept).
     */
    public function updateMe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'persona_slug' => ['sometimes', 'nullable', 'string', 'max:96', 'regex:/^'.self::SLUG_PATTERN.'$/'],
            'speak_enabled' => ['sometimes', 'boolean'],
            'browser_fallback' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $updates = [];

        if (array_key_exists('persona_slug', $validated)) {
            if ($validated['persona_slug'] !== null) {
                try {
                    $this->catalogue->usable($validated['persona_slug']);
                } catch (VoicePersonaUnavailable $e) {
                    return $this->unavailable($e);
                }
            }
            $updates['voice_persona_slug'] = $validated['persona_slug'];
        }

        $preferences = (array) ($user->preferences ?? []);
        if (array_key_exists('speak_enabled', $validated)) {
            $preferences['atlas_speak'] = (bool) $validated['speak_enabled'];
        }
        if (array_key_exists('browser_fallback', $validated)) {
            $preferences['atlas_browser_fallback'] = (bool) $validated['browser_fallback'];
        }
        $updates['preferences'] = $preferences;

        $user->forceFill($updates)->save();

        return response()->json(['data' => $this->mePayload($user->fresh())]);
    }

    /**
     * PUT /api/admin/tenant/voice-default {persona_slug: string|null} (role:admin)
     * Writes tenants.settings.voice.default_persona (other settings are kept).
     */
    public function updateTenantDefault(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'persona_slug' => ['present', 'nullable', 'string', 'max:96', 'regex:/^'.self::SLUG_PATTERN.'$/'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $tenant = $this->tenant($user);
        if ($tenant === null) {
            return response()->json(['message' => 'Tenant not resolved.'], 403);
        }

        $slug = $validated['persona_slug'];
        $persona = null;
        if ($slug !== null) {
            try {
                $persona = $this->catalogue->usable($slug);
            } catch (VoicePersonaUnavailable $e) {
                return $this->unavailable($e);
            }
        }

        $settings = (array) ($tenant->settings ?? []);
        $voice = (array) ($settings['voice'] ?? []);
        if ($slug === null) {
            unset($voice['default_persona']);
        } else {
            $voice['default_persona'] = $slug;
        }
        $settings['voice'] = $voice;
        $tenant->forceFill(['settings' => $settings])->save();

        return response()->json(['data' => ['default_persona' => $slug, 'persona' => $persona?->toApi()]]);
    }

    /**
     * POST /api/atlas/speak {text | message_id, persona_slug?} → audio bytes
     * (audio/mpeg for the default mp3). message_id is the interaction_id
     * POST /api/atlas/chat returned. 403 when voice.atlas_speak is off.
     */
    public function speak(Request $request): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $this->speech->enabled((string) $user->tenant_id)) {
            $e = AtlasSpeechException::disabled();

            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        $validated = $request->validate([
            'text' => ['required_without:message_id', 'nullable', 'string', 'max:'.$this->speech->maxChars()],
            'message_id' => ['required_without:text', 'nullable', 'string', 'max:64'],
            'persona_slug' => ['sometimes', 'nullable', 'string', 'max:96', 'regex:/^'.self::SLUG_PATTERN.'$/'],
        ]);

        $text = (string) ($validated['text'] ?? '');
        if (trim($text) === '' && ! empty($validated['message_id'])) {
            $text = $this->messageText($user, (string) $validated['message_id']);
            if ($text === null) {
                return response()->json(['message' => 'Message not found.'], 404);
            }
        }

        try {
            $spoken = $this->speech->speak($user, $text, $validated['persona_slug'] ?? null);
        } catch (VoicePersonaUnavailable $e) {
            return $this->unavailable($e);
        } catch (AtlasSpeechException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        $headers = [
            'Content-Type' => $spoken['content_type'],
            'Content-Length' => (string) strlen($spoken['audio']),
            'Cache-Control' => 'private, max-age=86400',
            'X-Atlas-Voice-Persona' => $spoken['persona'],
            'X-Atlas-Voice-Provider' => $spoken['provider'],
            'X-Atlas-Voice-Cache' => $spoken['cached'] ? 'hit' : 'miss',
        ];
        if ($spoken['fallback_from'] !== null) {
            $headers['X-Atlas-Voice-Fallback-From'] = $spoken['fallback_from'];
        }

        return response($spoken['audio'], 200, $headers);
    }

    /** @return array<string, mixed> */
    private function mePayload(User $user): array
    {
        $preferences = (array) ($user->preferences ?? []);
        $resolution = $this->catalogue->resolve($user);

        return [
            'persona_slug' => $user->getAttribute('voice_persona_slug'),
            'speak_enabled' => (bool) ($preferences['atlas_speak'] ?? false),
            'browser_fallback' => (bool) ($preferences['atlas_browser_fallback'] ?? true),
            'resolved_persona' => $resolution['persona']?->toApi(),
            'resolved_from' => $resolution['source'],
            'speak_available' => $this->speech->enabled((string) $user->tenant_id),
        ];
    }

    /**
     * The visible text of one of the user's Atlas replies, clipped to the
     * speakable length at a sentence end. Never queries the uuid column with
     * a non-uuid value (Postgres would abort the transaction).
     */
    private function messageText(User $user, string $messageId): ?string
    {
        if (! Str::isUuid($messageId)) {
            return null;
        }

        $row = DB::table('atlas_interactions')
            ->where('tenant_id', (string) $user->tenant_id)
            ->where('id', $messageId)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', (string) $user->id))
            ->first(['atlas_response']);
        if ($row === null) {
            return null;
        }

        $response = is_string($row->atlas_response) ? json_decode($row->atlas_response, true) : (array) $row->atlas_response;
        $parts = [];
        foreach (['text', 'reply', 'future_state', 'value', 'emotional_shift', 'action_summary'] as $key) {
            if (is_string($response[$key] ?? null) && trim($response[$key]) !== '') {
                $parts[] = rtrim(trim($response[$key]), '.').'.';
            }
        }

        $text = AtlasSpeechService::normalize(implode(' ', $parts));
        $max = $this->speech->maxChars();
        if (mb_strlen($text) > $max) {
            $clipped = mb_substr($text, 0, $max);
            $end = max((int) mb_strrpos($clipped, '. '), (int) mb_strrpos($clipped, '? '), (int) mb_strrpos($clipped, '! '));
            $text = $end > 0 ? mb_substr($clipped, 0, $end + 1) : $clipped;
        }

        return $text === '' ? null : $text;
    }

    private function unavailable(VoicePersonaUnavailable $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'reason' => $e->reason,
            'errors' => ['persona_slug' => [$e->getMessage()]],
        ], 422);
    }

    private function tenant(User $user): ?Tenant
    {
        $tenantId = (string) $user->tenant_id;

        return Str::isUuid($tenantId) ? Tenant::query()->find($tenantId) : null;
    }
}
