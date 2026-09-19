<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One candidate Atlas voice (plan D7 §7), projected from
 * inference/voice_personas.yaml by `voice:sync-personas`. The inference plane
 * receives toInferencePersona() on POST /tts and routes on its provider.
 *
 * Consent: stock and licensed voices are usable as they are; a cloned voice
 * (consent.type = cloned) is only ever spoken with complete consent —
 * subject_name, consent_doc_url and consented_at — and is labelled
 * "AI voice of <name>". Advisors and public figures are never cloned.
 */
class VoicePersona extends Model
{
    use HasUuids;

    public const PROVIDER_ELEVENLABS = 'elevenlabs';

    public const PROVIDER_FISHAUDIO = 'fishaudio';

    public const PROVIDER_INTRON = 'intron';

    public const PROVIDER_PIPER = 'piper';

    public const PROVIDER_AZURE = 'azure';

    /** Display / fallback order: ElevenLabs primary, Azure dormant last. */
    public const PROVIDERS = [
        self::PROVIDER_ELEVENLABS, self::PROVIDER_FISHAUDIO, self::PROVIDER_INTRON, self::PROVIDER_PIPER, self::PROVIDER_AZURE,
    ];

    public const CONSENT_STOCK = 'stock';

    public const CONSENT_LICENSED = 'licensed';

    public const CONSENT_CLONED = 'cloned';

    /** What a cloned voice's consent must carry before it may speak. */
    public const CLONED_CONSENT_FIELDS = ['subject_name', 'consent_doc_url', 'consented_at'];

    protected $attributes = [
        'tags' => '[]',
        'recommended_for' => '[]',
        'consent' => '{"type":"stock"}',
        'meta' => '{}',
        'is_active' => true,
        'is_default' => false,
        'disabled_by_default' => false,
    ];

    protected $fillable = [
        'slug', 'display_name', 'provider', 'provider_voice_id', 'language', 'accent', 'gender', 'style',
        'style_degree', 'rate', 'pitch', 'output_format', 'preview_url', 'cost_per_1k_chars', 'tags',
        'recommended_for', 'consent', 'kind', 'meta', 'is_active', 'is_default', 'disabled_by_default',
    ];

    protected $casts = [
        'style_degree' => 'float',
        'cost_per_1k_chars' => 'float',
        'tags' => 'array',
        'recommended_for' => 'array',
        'consent' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'disabled_by_default' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function consentType(): string
    {
        return (string) (($this->consent ?? [])['type'] ?? self::CONSENT_STOCK);
    }

    public function isCloned(): bool
    {
        return $this->consentType() === self::CONSENT_CLONED;
    }

    /** Stock and licensed voices need nothing more; a cloned voice needs every consent field, with a real date. */
    public function hasCompleteConsent(): bool
    {
        if (! $this->isCloned()) {
            return true;
        }

        $consent = (array) ($this->consent ?? []);
        foreach (self::CLONED_CONSENT_FIELDS as $field) {
            if (! is_string($consent[$field] ?? null) || trim($consent[$field]) === '') {
                return false;
            }
        }

        try {
            return Carbon::parse($consent['consented_at'])->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether the persona names a concrete voice its provider can render. */
    public function isSpeakable(): bool
    {
        $voiceId = trim((string) $this->provider_voice_id);

        return match ($this->provider) {
            self::PROVIDER_PIPER => true,
            self::PROVIDER_INTRON => count(array_filter(explode('/', $voiceId))) === 3
                || (! empty($this->meta['intron']['voice_accent']) && ! empty($this->meta['intron']['voice_gender'])),
            default => $voiceId !== '',
        };
    }

    public function consentLabel(): ?string
    {
        return $this->isCloned() && ! empty($this->consent['subject_name'])
            ? 'AI voice of '.$this->consent['subject_name']
            : null;
    }

    /**
     * The persona as POST {inference}/tts expects it (tts_providers.normalize_persona).
     *
     * @return array<string, mixed>
     */
    public function toInferencePersona(): array
    {
        $numeric = fn ($value) => is_string($value) && is_numeric($value) ? (float) $value : $value;

        return array_filter([
            'slug' => $this->slug,
            'provider' => $this->provider,
            'voice_id' => $this->provider_voice_id,
            'provider_voice_id' => $this->provider_voice_id,
            'language' => $this->language,
            'accent' => $this->accent,
            'gender' => $this->gender,
            'style' => $this->style,
            'style_degree' => $this->style_degree,
            'rate' => $numeric($this->rate),
            'pitch' => $numeric($this->pitch),
            'output_format' => $this->output_format,
            'fish_model' => $this->meta['fish_model'] ?? null,
            'intron' => $this->meta['intron'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The picker card (GET /api/voice/personas). No provider voice ids or consent documents.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'slug' => $this->slug,
            'display_name' => $this->display_name,
            'provider' => $this->provider,
            'language' => $this->language,
            'accent' => $this->accent,
            'gender' => $this->gender,
            'style' => $this->style,
            'tags' => array_values((array) ($this->tags ?? [])),
            'recommended_for' => array_values((array) ($this->recommended_for ?? [])),
            'cost_per_1k_chars' => $this->cost_per_1k_chars,
            'consent' => ['type' => $this->consentType(), 'label' => $this->consentLabel()],
            'is_default' => (bool) $this->is_default,
            'preview_url' => $this->preview_url,
            'preview_endpoint' => '/api/voice/personas/'.$this->slug.'/preview',
        ];
    }
}
