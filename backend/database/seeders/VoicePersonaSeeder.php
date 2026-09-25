<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\VoicePersona;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds voice_personas from inference/voice_personas.yaml (plan D7 §7) — the
 * same catalogue the listening page renders. Upserts by slug and is idempotent,
 * so it is safe in deploys (`php artisan voice:sync-personas`).
 *
 *  - Azure personas (and any provider marked disabled_by_default) are skipped
 *    unless --include-azure: Jason rejected the Azure voices (2026-09-16).
 *  - A persona with no renderable voice yet (a Voice Design candidate or an
 *    undiscovered library placeholder) is stored inactive.
 *  - Personas no longer in the file are deactivated, never deleted, so a
 *    user's saved choice falls back cleanly.
 *  - is_default marks the file's `default_persona` (else config
 *    voice.default_persona) when that persona is active.
 */
class VoicePersonaSeeder extends Seeder
{
    /** Keys stored in their own columns; everything else in a persona entry goes to `meta`. */
    private const COLUMNS = [
        'slug', 'display_name', 'provider', 'provider_voice_id', 'language', 'accent', 'gender', 'style', 'style_degree',
        'rate', 'pitch', 'output_format', 'preview_url', 'cost_per_1k_chars', 'tags', 'recommended_for', 'consent', 'kind',
        'is_active', 'is_default', 'disabled_by_default',
    ];

    public bool $includeAzure = false;

    public ?string $path = null;

    public function run(): void
    {
        $summary = $this->sync($this->path, $this->includeAzure);

        $this->command?->info(sprintf(
            'voice personas: %d created, %d updated, %d skipped, %d deactivated; %d active; default %s',
            $summary['created'], $summary['updated'], $summary['skipped'], $summary['deactivated'], $summary['active'],
            $summary['default'] ?? 'none',
        ));
    }

    /**
     * @return array{path: string, created: int, updated: int, skipped: int, deactivated: int, active: int, default: ?string, slugs: list<string>}
     */
    public function sync(?string $path = null, bool $includeAzure = false): array
    {
        $path = $path !== null && trim($path) !== '' ? $path : self::cataloguePath();
        if (! is_readable($path)) {
            throw new \RuntimeException("Voice persona catalogue not found at {$path}. Set VOICE_PERSONAS_PATH or services.inference.path.");
        }

        $parsed = Yaml::parseFile($path);
        if (! is_array($parsed) || ! is_array($parsed['personas'] ?? null)) {
            throw new \RuntimeException("{$path} has no personas list.");
        }

        $providers = (array) ($parsed['providers'] ?? []);
        $defaultSlug = trim((string) ($parsed['default_persona'] ?? config('voice.default_persona') ?? ''));
        $summary = [
            'path' => $path, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'deactivated' => 0,
            'active' => 0, 'default' => null, 'slugs' => [],
        ];

        DB::transaction(function () use ($parsed, $providers, $defaultSlug, $includeAzure, &$summary): void {
            foreach ($parsed['personas'] as $entry) {
                if (! is_array($entry) || empty($entry['slug']) || empty($entry['provider'])) {
                    $summary['skipped']++;

                    continue;
                }

                $provider = strtolower(trim((string) $entry['provider']));
                $disabledByDefault = (bool) ($providers[$provider]['disabled_by_default'] ?? $provider === VoicePersona::PROVIDER_AZURE);
                if ($disabledByDefault && ! $includeAzure) {
                    $summary['skipped']++;

                    continue;
                }

                $persona = VoicePersona::query()->firstOrNew(['slug' => (string) $entry['slug']]);
                $persona->fill($this->attributes($entry, $provider, $disabledByDefault));
                $persona->is_active = (bool) ($entry['is_active'] ?? true) && $persona->isSpeakable();
                $summary[$persona->exists ? 'updated' : 'created']++;
                $persona->save();
                $summary['slugs'][] = $persona->slug;
            }

            $summary['deactivated'] = VoicePersona::query()
                ->whereNotIn('slug', $summary['slugs'] === [] ? [''] : $summary['slugs'])
                ->where('is_active', true)
                ->update(['is_active' => false, 'is_default' => false]);

            VoicePersona::query()->where('is_default', true)->where('slug', '!=', $defaultSlug)->update(['is_default' => false]);
            if ($defaultSlug !== '' && VoicePersona::query()->where('slug', $defaultSlug)->where('is_active', true)->update(['is_default' => true]) > 0) {
                $summary['default'] = $defaultSlug;
            }

            $summary['active'] = VoicePersona::query()->where('is_active', true)->count();
        });

        return $summary;
    }

    /** inference/voice_personas.yaml: voice.personas_path → services.inference.path → base_path('../inference'). */
    public static function cataloguePath(): string
    {
        $configured = trim((string) config('voice.personas_path', ''));
        if ($configured !== '') {
            return $configured;
        }

        $inference = trim((string) config('services.inference.path', ''));
        $root = $inference !== '' ? $inference : base_path('..'.DIRECTORY_SEPARATOR.'inference');

        return rtrim($root, '\\/').DIRECTORY_SEPARATOR.'voice_personas.yaml';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function attributes(array $entry, string $provider, bool $disabledByDefault): array
    {
        $string = fn ($value, int $max): ?string => $value === null || $value === '' ? null : mb_substr((string) $value, 0, $max);
        $number = fn ($value): ?float => is_numeric($value) ? (float) $value : null;
        $list = fn ($value): array => array_values(array_map('strval', array_filter((array) ($value ?? []), 'is_scalar')));

        $consent = is_array($entry['consent'] ?? null) ? $entry['consent'] : [];
        $consent['type'] = (string) ($consent['type'] ?? VoicePersona::CONSENT_STOCK);

        return [
            'display_name' => (string) ($entry['display_name'] ?? $entry['slug']),
            'provider' => $provider,
            'provider_voice_id' => $string($entry['provider_voice_id'] ?? null, 190),
            'language' => $string($entry['language'] ?? null, 16),
            'accent' => $string($entry['accent'] ?? null, 96),
            'gender' => $string($entry['gender'] ?? null, 24),
            'style' => $string($entry['style'] ?? null, 48),
            'style_degree' => $number($entry['style_degree'] ?? null),
            'rate' => $string($entry['rate'] ?? null, 16),
            'pitch' => $string($entry['pitch'] ?? null, 16),
            'output_format' => $string($entry['output_format'] ?? null, 64),
            'preview_url' => $string($entry['preview_url'] ?? null, 500),
            'cost_per_1k_chars' => $number($entry['cost_per_1k_chars'] ?? null),
            'tags' => $list($entry['tags'] ?? []),
            'recommended_for' => $list($entry['recommended_for'] ?? []),
            'consent' => $consent,
            'kind' => $string($entry['kind'] ?? null, 24),
            'meta' => array_diff_key($entry, array_flip(self::COLUMNS)),
            'disabled_by_default' => $disabledByDefault,
        ];
    }
}
