<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\User;
use App\Models\VoicePersona;
use App\Services\CostGovernor;
use App\Services\FeatureFlag;
use App\Services\VoiceSafetyGuard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Atlas speaks (plan D7 §7): text → the resolved persona → the inference
 * plane's POST /tts (which routes on persona.provider and falls back
 * persona provider → elevenlabs → piper) → audio bytes.
 *
 *  - gated by the `voice.atlas_speak` flag (403) and VoiceSafetyGuard (the
 *    voice kill switch and the tenant budget);
 *  - at most voice.speak.max_chars (2,000) characters;
 *  - clips are cached for 24 h on the local disk under
 *    voice/atlas-speak/{tenant}/{sha256(text|persona)}.{ext}; a clip the plane
 *    produced with a fallback voice is served but never cached, so one
 *    provider hiccup does not pin the wrong voice for a day;
 *  - each fresh render records `tts` usage through CostGovernor::recordUsage.
 */
final class AtlasSpeechService
{
    private const EXTENSIONS = ['audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg'];

    public function __construct(
        private readonly VoicePersonaCatalogue $catalogue,
        private readonly VoiceSafetyGuard $guard,
        private readonly CostGovernor $costGovernor,
    ) {}

    public function enabled(string $tenantId): bool
    {
        return FeatureFlag::on('voice.atlas_speak', $tenantId);
    }

    /**
     * @return array{audio: string, content_type: string, persona: string, provider: string, cached: bool, characters: int, fallback_from: ?string}
     *
     * @throws AtlasSpeechException|VoicePersonaUnavailable
     */
    public function speak(User $user, string $text, ?string $personaSlug = null): array
    {
        $tenantId = (string) $user->tenant_id;
        if (! $this->enabled($tenantId)) {
            throw AtlasSpeechException::disabled();
        }

        $text = self::normalize($text);
        $max = $this->maxChars();
        if ($text === '') {
            throw AtlasSpeechException::invalid('There is nothing to say.');
        }
        if (mb_strlen($text) > $max) {
            throw AtlasSpeechException::invalid("Atlas speaks at most {$max} characters at a time.");
        }

        $persona = $personaSlug !== null && trim($personaSlug) !== ''
            ? $this->catalogue->usable(trim($personaSlug))
            : $this->catalogue->resolveForUser($user);
        if ($persona === null) {
            throw AtlasSpeechException::noPersona();
        }

        $characters = mb_strlen($text);
        $estimatedCost = round($characters / 1000 * (float) ($persona->cost_per_1k_chars ?? 0), 6);
        $gate = $this->guard->checkSpeech($tenantId, $estimatedCost);
        if (! $gate['allowed']) {
            throw AtlasSpeechException::blocked((string) $gate['reason']);
        }

        $key = self::cacheKey($text, $persona->slug);
        $hit = $this->fromCache($tenantId, $key);
        if ($hit !== null) {
            return [
                'audio' => $hit['audio'], 'content_type' => $hit['content_type'], 'persona' => $persona->slug,
                'provider' => $persona->provider, 'cached' => true, 'characters' => $characters, 'fallback_from' => null,
            ];
        }

        $rendered = $this->render($persona, $text);

        if ($rendered['fallback_from'] === null && $rendered['provider'] === $persona->provider) {
            $this->toCache($tenantId, $key, $rendered['audio'], $rendered['content_type']);
        }

        $this->recordUsage($tenantId, $user, $persona, $rendered, $characters);

        return [
            'audio' => $rendered['audio'], 'content_type' => $rendered['content_type'], 'persona' => $persona->slug,
            'provider' => $rendered['provider'], 'cached' => false, 'characters' => $characters,
            'fallback_from' => $rendered['fallback_from'],
        ];
    }

    public function maxChars(): int
    {
        return max(1, (int) config('voice.speak.max_chars', 2000));
    }

    /** sha256(text|persona) — the 24 h cache key. */
    public static function cacheKey(string $text, string $personaSlug): string
    {
        return hash('sha256', $text.'|'.$personaSlug);
    }

    /** Collapse whitespace so the same sentence always hits the same clip. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * POST {inference}/tts {text, provider, voice, persona, format: mp3}.
     *
     * @return array{audio: string, content_type: string, provider: string, fallback_from: ?string}
     */
    private function render(VoicePersona $persona, string $text): array
    {
        $request = Http::baseUrl((string) config('services.inference.url', 'http://localhost:9000'))
            ->timeout((int) config('voice.speak.timeout', 60))
            ->acceptJson();
        $token = (string) config('services.inference.token', '');
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post('/tts', [
                'text' => $text,
                'provider' => $persona->provider,
                'voice' => $persona->provider_voice_id,
                'persona' => $persona->toInferencePersona(),
                'format' => (string) config('voice.speak.format', 'mp3'),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('voice.atlas_speak_unreachable', ['persona' => $persona->slug, 'error' => $e->getMessage()]);

            throw AtlasSpeechException::upstream('The voice service is unreachable right now.', 503);
        }

        if ($response->failed()) {
            Log::warning('voice.atlas_speak_failed', [
                'persona' => $persona->slug, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300),
            ]);

            throw AtlasSpeechException::upstream(
                $response->status() === 503 ? 'The voice service has no voice configured.' : 'The voice service could not speak this.',
                $response->status() === 503 ? 503 : 502,
            );
        }

        $audio = base64_decode((string) $response->json('audio_base64', ''), true);
        if ($audio === false || $audio === '') {
            throw AtlasSpeechException::upstream('The voice service returned no audio.');
        }

        $fallbackFrom = $response->json('fallback_from');

        return [
            'audio' => $audio,
            'content_type' => (string) ($response->json('content_type') ?: 'audio/mpeg'),
            'provider' => (string) ($response->json('provider') ?: $persona->provider),
            'fallback_from' => is_string($fallbackFrom) && $fallbackFrom !== '' ? $fallbackFrom : null,
        ];
    }

    /** @return array{audio: string, content_type: string}|null */
    private function fromCache(string $tenantId, string $key): ?array
    {
        $disk = $this->disk();
        $ttl = (int) config('voice.speak.cache_ttl_seconds', 86400);

        foreach (array_unique(self::EXTENSIONS) as $ext) {
            $path = $this->cachePath($tenantId, $key, $ext);
            try {
                if (! $disk->exists($path)) {
                    continue;
                }
                if ($disk->lastModified($path) < now()->getTimestamp() - $ttl) {
                    $disk->delete($path);

                    continue;
                }
                $audio = (string) $disk->get($path);
            } catch (\Throwable $e) {
                Log::warning('voice.atlas_speak_cache_read_failed', ['path' => $path, 'error' => $e->getMessage()]);

                return null;
            }
            if ($audio !== '') {
                return ['audio' => $audio, 'content_type' => (string) array_search($ext, self::EXTENSIONS, true)];
            }
        }

        return null;
    }

    private function toCache(string $tenantId, string $key, string $audio, string $contentType): void
    {
        $ext = self::EXTENSIONS[strtolower(trim(explode(';', $contentType)[0]))] ?? null;
        if ($ext === null) {
            return;
        }
        try {
            $this->disk()->put($this->cachePath($tenantId, $key, $ext), $audio);
        } catch (\Throwable $e) {
            Log::warning('voice.atlas_speak_cache_write_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
        }
    }

    public function cachePath(string $tenantId, string $key, string $ext = 'mp3'): string
    {
        return trim((string) config('voice.speak.cache_prefix', 'voice/atlas-speak'), '/')."/{$tenantId}/{$key}.{$ext}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('voice.speak.cache_disk', 'local'));
    }

    /** @param array{provider: string, fallback_from: ?string} $rendered */
    private function recordUsage(string $tenantId, User $user, VoicePersona $persona, array $rendered, int $characters): void
    {
        if (! method_exists($this->costGovernor, 'recordUsage')) {
            return;
        }

        // Billed at the rate of the provider that actually spoke; a fallback voice has no catalogue price here.
        $rate = $rendered['provider'] === $persona->provider ? (float) ($persona->cost_per_1k_chars ?? 0) : 0.0;

        try {
            $this->costGovernor->recordUsage($tenantId, 'tts', round($characters / 1000 * $rate, 6), [
                'surface' => 'atlas_speak',
                'persona' => $persona->slug,
                'provider' => $rendered['provider'],
                'fallback_from' => $rendered['fallback_from'],
                'characters' => $characters,
                'user_id' => (string) $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('voice.atlas_speak_usage_not_recorded', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
        }
    }
}
