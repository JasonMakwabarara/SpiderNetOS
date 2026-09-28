<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VoicePersona;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Which voice Atlas speaks with (plan D7 §7).
 *
 * resolveForUser(): users.voice_persona_slug → tenants.settings.voice.default_persona
 * → config('voice.default_persona') → the first active persona. Every step
 * skips a persona that is unknown, inactive, has no voice id, or is a cloned
 * voice without complete consent — the catalogue refuses those everywhere.
 */
final class VoicePersonaCatalogue
{
    public const SOURCE_USER = 'user';

    public const SOURCE_TENANT = 'tenant';

    public const SOURCE_CONFIG = 'config';

    public const SOURCE_CATALOGUE = 'catalogue';

    /**
     * Active personas Atlas may speak with: the default first, then provider
     * order (ElevenLabs primary … Azure dormant), then slug.
     *
     * @return Collection<int, VoicePersona>
     */
    public function active(): Collection
    {
        $rank = array_flip(VoicePersona::PROVIDERS);

        return VoicePersona::query()
            ->active()
            ->get()
            ->filter(fn (VoicePersona $persona): bool => $this->refusal($persona) === null)
            ->sort(fn (VoicePersona $a, VoicePersona $b): int => [$a->is_default ? 0 : 1, $rank[$a->provider] ?? 99, $a->slug]
                <=> [$b->is_default ? 0 : 1, $rank[$b->provider] ?? 99, $b->slug])
            ->values();
    }

    public function find(string $slug): ?VoicePersona
    {
        return VoicePersona::query()->where('slug', $slug)->first();
    }

    /** Why the persona cannot speak (a VoicePersonaUnavailable reason), or null when it can. */
    public function refusal(?VoicePersona $persona): ?string
    {
        return match (true) {
            $persona === null => VoicePersonaUnavailable::UNKNOWN,
            ! $persona->is_active => VoicePersonaUnavailable::INACTIVE,
            $persona->isCloned() && ! $persona->hasCompleteConsent() => VoicePersonaUnavailable::CONSENT_INCOMPLETE,
            ! $persona->isSpeakable() => VoicePersonaUnavailable::NO_VOICE,
            default => null,
        };
    }

    /** A persona named explicitly (request, settings) that must be usable. */
    public function usable(string $slug): VoicePersona
    {
        $persona = $this->find($slug);
        $refusal = $this->refusal($persona);
        if ($refusal !== null) {
            throw new VoicePersonaUnavailable($slug, $refusal);
        }

        return $persona;
    }

    public function resolveForUser(User $user): ?VoicePersona
    {
        return $this->resolve($user)['persona'];
    }

    /**
     * @return array{persona: ?VoicePersona, source: ?string, skipped: list<array{source: string, slug: string, reason: string}>}
     */
    public function resolve(User $user): array
    {
        $skipped = [];
        foreach ($this->candidates($user) as $source => $slug) {
            $persona = $this->find($slug);
            $refusal = $this->refusal($persona);
            if ($refusal === null) {
                return ['persona' => $persona, 'source' => $source, 'skipped' => $skipped];
            }
            $skipped[] = ['source' => $source, 'slug' => $slug, 'reason' => $refusal];
            if ($refusal === VoicePersonaUnavailable::CONSENT_INCOMPLETE) {
                Log::warning('voice.persona_refused_cloned_without_consent', ['slug' => $slug, 'source' => $source, 'user_id' => $user->id]);
            }
        }

        $first = $this->active()->first();

        return ['persona' => $first, 'source' => $first ? self::SOURCE_CATALOGUE : null, 'skipped' => $skipped];
    }

    /**
     * The explicitly chosen slugs, in resolution order.
     *
     * @return array<string, string>
     */
    public function candidates(User $user): array
    {
        $tenant = $user->tenant_id ? Tenant::query()->find($user->tenant_id) : null;

        return array_filter([
            self::SOURCE_USER => $this->slugOrNull($user->getAttribute('voice_persona_slug')),
            self::SOURCE_TENANT => $this->slugOrNull(data_get($tenant?->settings, 'voice.default_persona')),
            self::SOURCE_CONFIG => $this->slugOrNull(config('voice.default_persona')),
        ], fn (?string $slug): bool => $slug !== null);
    }

    private function slugOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
