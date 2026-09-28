<?php

declare(strict_types=1);

namespace App\Services\Hannah;

use App\Services\Brain\BrainMarkdown;
use App\Services\Brain\BrainStore;

/**
 * Turns the Knowledge brain into Hannah AI's Company payload (plan D7 §1).
 *
 * This is the whole point of the hand-off. "I need to market this new product"
 * should not open a form. Everything Hannah's onboarding asks for — what the
 * business is, how it sounds, who it sells to, what it sells, what it must
 * never say — is already written down in the brain, so the owner arrives with
 * it already filled in.
 *
 * Mapping (brain -> Hannah `companies`):
 *   brand/voice.md fm.brand|business/profile.md fm.name  -> name
 *   fm.website                                           -> website
 *   fm.tagline                                           -> tagline
 *   fm.industry                                          -> industry
 *   fm.one_liner + business/profile.md "What we do"      -> description
 *   fm.palette.charge (or the first colour)              -> brand_color
 *   fm.tone + "## Voice"                                 -> tone_of_voice
 *   customers/icp.md "Who we sell to"                    -> target_audience
 *   "## Do and don't" + people/user.md "Never say"       -> specific_instructions
 *   offer/offer.md fm.products + "Products and services" -> products[]
 *   fm.logo                                              -> assets[] by URL
 *
 * Two rules:
 *  - Nothing is invented. A field the brain does not have is omitted, not
 *    guessed — a guessed tagline would go out on real marketing.
 *  - "Never say or offer" travels with the brand. It is the single most
 *    important line to carry across a product boundary, because the other
 *    product is the one that will actually publish something.
 */
class HannahBrandMapper
{
    public const SOURCE_PATHS = [
        'brand/voice.md',
        'brand/identity.md',
        'business/profile.md',
        'customers/icp.md',
        'offer/offer.md',
        'people/user.md',
    ];

    public function __construct(private readonly BrainStore $brain) {}

    /**
     * @return array{payload: array<string, mixed>, missing: list<string>, hash: string}
     */
    public function map(string $tenantId): array
    {
        $files = [];
        $missing = [];

        foreach (self::SOURCE_PATHS as $path) {
            $file = $this->read($tenantId, $path);
            if ($file === null) {
                $missing[] = $path;

                continue;
            }
            $files[$path] = $file;
        }

        $voice = $files['brand/voice.md'] ?? null;
        $identity = $files['brand/identity.md'] ?? null;
        $profile = $files['business/profile.md'] ?? null;
        $icp = $files['customers/icp.md'] ?? null;
        $offer = $files['offer/offer.md'] ?? null;
        $user = $files['people/user.md'] ?? null;

        $fm = fn (?array $file, string $key): mixed => $file === null ? null : ($file['frontmatter'][$key] ?? null);

        $name = $this->str($fm($voice, 'brand')) ?? $this->str($fm($profile, 'name'));
        $tone = $this->list($fm($voice, 'tone'));

        $payload = array_filter([
            'name' => $name,
            'website' => $this->str($fm($voice, 'website')) ?? $this->str($fm($profile, 'website')),
            'tagline' => $this->str($fm($voice, 'tagline')) ?? $this->str($fm($identity, 'tagline')),
            'industry' => $this->str($fm($voice, 'industry')) ?? $this->str($fm($profile, 'industry')),
            'description' => $this->description($voice, $profile),
            'brand_color' => $this->brandColour($voice, $identity),
            'tone_of_voice' => $this->toneOfVoice($tone, $voice),
            'target_audience' => $this->section($icp, 'Who we sell to'),
            'specific_instructions' => $this->instructions($voice, $user),
            'products' => $this->products($offer),
            'assets' => $this->assets($voice, $identity),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);

        return [
            'payload' => $payload,
            'missing' => $missing,
            // The hash is over the payload, not over the files: a brain edit
            // that changes nothing Hannah can see must not trigger a push.
            'hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    /** What the owner should fill in before the hand-off is worth making. */
    public function readiness(string $tenantId): array
    {
        $mapped = $this->map($tenantId);
        $payload = $mapped['payload'];

        $required = ['name', 'description', 'tone_of_voice'];
        $absent = array_values(array_filter($required, fn (string $key): bool => ! isset($payload[$key])));

        return [
            'ready' => $absent === [],
            'missing_fields' => $absent,
            'missing_files' => $mapped['missing'],
            'fields' => array_keys($payload),
        ];
    }

    // ------------------------------------------------------------------ //

    private function description(?array $voice, ?array $profile): ?string
    {
        $parts = array_filter([
            $this->str($voice['frontmatter']['one_liner'] ?? null),
            $this->section($profile, 'What we do'),
            $this->section($profile, 'What makes us different'),
        ]);

        return $parts === [] ? null : mb_substr(implode("\n\n", $parts), 0, 2000);
    }

    private function brandColour(?array $voice, ?array $identity): ?string
    {
        foreach ([$voice, $identity] as $file) {
            $palette = $file['frontmatter']['palette'] ?? null;
            if (! is_array($palette) || $palette === []) {
                continue;
            }

            // `charge` is the brand's accent in the SpiderNet palette
            // convention; otherwise take the first colour that looks like one.
            foreach (['charge', 'primary', 'accent'] as $key) {
                if (isset($palette[$key]) && $this->isColour((string) $palette[$key])) {
                    return (string) $palette[$key];
                }
            }
            foreach ($palette as $value) {
                if (is_string($value) && $this->isColour($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function isColour(string $value): bool
    {
        return (bool) preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value));
    }

    /** @param  list<string>  $tone */
    private function toneOfVoice(array $tone, ?array $voice): ?string
    {
        $parts = [];
        if ($tone !== []) {
            $parts[] = implode(', ', $tone);
        }

        foreach (['Tone', 'Voice'] as $section) {
            $body = $this->section($voice, $section);
            if ($body !== null) {
                $parts[] = $body;
            }
        }

        return $parts === [] ? null : mb_substr(implode("\n\n", $parts), 0, 2000);
    }

    /**
     * The prohibitions. Carried across the boundary verbatim, because Hannah
     * is the product that actually publishes.
     */
    private function instructions(?array $voice, ?array $user): ?string
    {
        $parts = [];

        $dos = $this->section($voice, "Do and don't");
        if ($dos !== null) {
            $parts[] = $dos;
        }

        $never = $this->section($user, 'Never say or offer');
        if ($never !== null) {
            $parts[] = "Never say or offer:\n".$never;
        }

        return $parts === [] ? null : mb_substr(implode("\n\n", $parts), 0, 2000);
    }

    /** @return list<array<string, string>> */
    private function products(?array $offer): array
    {
        if ($offer === null) {
            return [];
        }

        $named = $this->list($offer['frontmatter']['products'] ?? null);
        $body = $this->section($offer, 'Products and services');
        $pricing = $this->str($offer['frontmatter']['pricing'] ?? null);

        if ($named === [] && $body === null) {
            return [];
        }

        if ($named === []) {
            return [array_filter([
                'name' => 'What we sell',
                'description' => $body,
                'pricing' => $pricing,
            ])];
        }

        return array_map(fn (string $name): array => array_filter([
            'name' => mb_substr($name, 0, 120),
            // Every product carries the same prose: the brain describes the
            // offer as a whole, and inventing a per-product description would
            // be exactly the kind of guess this mapper refuses to make.
            'description' => $body,
            'pricing' => $pricing,
        ]), $named);
    }

    /** @return list<array<string, string>> */
    private function assets(?array $voice, ?array $identity): array
    {
        $assets = [];

        foreach ([$voice, $identity] as $file) {
            $logo = $this->str($file['frontmatter']['logo'] ?? null);
            if ($logo !== null && str_starts_with($logo, 'http')) {
                $assets[] = ['kind' => 'logo', 'url' => $logo];
                break;
            }
        }

        return $assets;
    }

    // ------------------------------------------------------------------ //

    /** @return array{content: string, frontmatter: array<string, mixed>}|null */
    private function read(string $tenantId, string $path): ?array
    {
        try {
            $file = $this->brain->read($tenantId, $path);
        } catch (\Throwable) {
            return null;
        }

        if ($file === null || trim((string) $file->content) === '') {
            return null;
        }

        return [
            'content' => (string) $file->content,
            'frontmatter' => is_array($file->frontmatter) ? $file->frontmatter : [],
        ];
    }

    /** @param  array{content: string}|null  $file */
    private function section(?array $file, string $heading): ?string
    {
        if ($file === null) {
            return null;
        }

        $body = BrainMarkdown::section($file['content'], $heading);

        return $body === null || trim($body) === '' ? null : trim($body);
    }

    private function str(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
            $value,
        ), fn (string $v): bool => $v !== ''));
    }
}
