<?php

declare(strict_types=1);

namespace App\Services\Board;

use App\Services\FeatureFlag;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads packages/advisors/<slug>/PERSONA.md (and the flag-gated private roster
 * under advisors/private/) into seat definitions.
 *
 * The registry is where the likeness rule is enforced rather than merely
 * documented: `promptFor()` composes "reason with the frameworks of X" and
 * there is no code path that produces "you are X". A seat whose
 * `likeness_mode` is `inspired_by` or `tenant_authored` still only ever
 * receives the frameworks, never the identity.
 */
class AdvisorRegistry
{
    public const CHAIR_SEAT = 'chairman';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $seats = null;

    public function __construct(private readonly ?string $root = null) {}

    public function root(): string
    {
        return rtrim((string) config('board.advisors_root', dirname(base_path()).'/packages/advisors'), '\\/');
    }

    /**
     * Every seat on disk, keyed by slug, ordered by `order`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->seats !== null) {
            return $this->seats;
        }

        $root = $this->root ?? $this->root();
        $seats = [];

        foreach ((array) glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'PERSONA.md') as $path) {
            $seat = $this->parse((string) $path);
            if ($seat !== null) {
                $seats[$seat['slug']] = $seat;
            }
        }

        foreach ((array) glob($root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'*.md') as $path) {
            $seat = $this->parse((string) $path);
            if ($seat !== null) {
                $seats[$seat['slug']] = $seat;
            }
        }

        uasort($seats, fn (array $a, array $b): int => [$a['order'], $a['slug']] <=> [$b['order'], $b['slug']]);

        return $this->seats = $seats;
    }

    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * The seats that sit on a board for this tenant.
     *
     * A seat with `requires_flag` only sits when that flag is on, and a seat
     * with `tenant_visible: false` never sits for anyone else — the private
     * roster must not be able to leak into another tenant's board by being
     * asked for by name.
     *
     * @return list<array<string, mixed>>
     */
    public function seatsFor(string $tenantId, bool $includeChair = false): array
    {
        $seats = [];

        foreach ($this->all() as $seat) {
            if ($seat['seat'] === self::CHAIR_SEAT && ! $includeChair) {
                continue;
            }
            if (! $this->available($seat, $tenantId)) {
                continue;
            }
            $seats[] = $seat;
        }

        // A private seat replaces the archetype it extends, rather than sitting
        // beside it: the board is five voices, not five plus five.
        $extended = array_filter(array_column($seats, 'extends'));

        return array_values(array_filter($seats, fn (array $s): bool => ! in_array($s['slug'], $extended, true)));
    }

    public function chair(string $tenantId): ?array
    {
        foreach ($this->all() as $seat) {
            if ($seat['seat'] === self::CHAIR_SEAT && $this->available($seat, $tenantId)) {
                return $seat;
            }
        }

        return null;
    }

    public function available(array $seat, string $tenantId): bool
    {
        $flag = $seat['requires_flag'] ?? null;

        if (! ($seat['tenant_visible'] ?? true) && ! is_string($flag)) {
            return false;
        }

        if (is_string($flag) && $flag !== '') {
            return FeatureFlag::on($flag, $tenantId);
        }

        return true;
    }

    /**
     * The seat's system prompt.
     *
     * The one line this method exists for: a seat is told to reason *with the
     * frameworks of* someone, never to *be* them. There is deliberately no
     * parameter that would change that.
     */
    public function promptFor(array $seat): string
    {
        $base = $this->resolveInheritance($seat);

        $lines = [
            'You are '.$base['display_name'].', one seat on a board of advisors inside SpiderNetOS.',
        ];

        $inspired = $base['inspired_by'] ?? null;
        if (is_string($inspired) && $inspired !== '') {
            $lines[] = 'You reason with the frameworks of '.$inspired.'. You are not that person, you do not';
            $lines[] = 'speak in their name, and you never claim their agreement or endorsement. Do not quote them.';
        }

        if (($base['stance_priors'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'How you think:';
            foreach ($base['stance_priors'] as $prior) {
                $lines[] = '- '.$prior;
            }
        }

        if (($base['question_style'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'The questions you ask first:';
            foreach ($base['question_style'] as $question) {
                $lines[] = '- '.$question;
            }
        }

        $lines[] = '';
        $lines[] = 'Kill criteria, in your style: '.($base['kill_criteria_style'] ?? 'a condition that would mean stop.');
        $lines[] = '';
        $lines[] = 'Rules that bind every seat:';
        $lines[] = '- Use only the facts in the BRIEF. Never invent a figure, a customer or a date.';
        $lines[] = '- If the brief does not contain what you need, say so in `what_would_change_my_mind`.';
        $lines[] = '- You are not a lawyer, an accountant or a regulated adviser, and you do not pretend to be.';
        $lines[] = '- You never address another seat directly. The chair mediates.';

        return implode("\n", $lines);
    }

    /**
     * Everything that would identify this seat inside its own free text.
     *
     * Whole phrases and proper names only. The seat's display name, the name
     * it is a label for, and whatever `redact_terms` the persona file lists —
     * never the framework words themselves, because a round-two prompt with
     * "grand slam a seat" in it is worse than one that says "offer".
     *
     * @return list<string>
     */
    public function redactTermsFor(array $seat): array
    {
        $resolved = $this->resolveInheritance($seat);

        $terms = [
            $seat['display_name'] ?? null,
            $resolved['display_name'] ?? null,
            str_replace('-', ' ', (string) ($seat['slug'] ?? '')),
            str_replace('-', ' ', (string) ($resolved['slug'] ?? '')),
        ];

        // "The Offer Architect" also has to catch a bare "Offer Architect".
        foreach (array_filter($terms) as $term) {
            if (stripos((string) $term, 'the ') === 0) {
                $terms[] = mb_substr((string) $term, 4);
            }
        }

        foreach ([$seat, $resolved] as $source) {
            foreach ((array) ($source['redact_terms'] ?? []) as $term) {
                $terms[] = (string) $term;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($t): string => trim((string) $t),
            $terms,
        ), fn (string $t): bool => $t !== '')));
    }

    /** A private-roster seat inherits the archetype's thinking; only the label differs. */
    public function resolveInheritance(array $seat): array
    {
        $extends = $seat['extends'] ?? null;
        if (! is_string($extends) || $extends === '') {
            return $seat;
        }

        $parent = $this->get($extends);
        if ($parent === null) {
            return $seat;
        }

        // The child's own display name and inspired_by win; everything that
        // decides how the seat *thinks* comes from the archetype.
        return array_merge($parent, array_filter([
            'slug' => $seat['slug'],
            'display_name' => $seat['display_name'] ?? null,
            'inspired_by' => $seat['inspired_by'] ?? null,
            'likeness_mode' => $seat['likeness_mode'] ?? null,
            'tenant_visible' => false,
            'requires_flag' => $seat['requires_flag'] ?? null,
        ], fn ($v): bool => $v !== null));
    }

    /** @return array<string, mixed>|null */
    private function parse(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || ! str_starts_with(ltrim($raw), '---')) {
            Log::warning('board.persona_unreadable', ['path' => $path]);

            return null;
        }

        $parts = preg_split('/^---\s*$/m', ltrim($raw), 3);
        if (! is_array($parts) || count($parts) < 3) {
            return null;
        }

        try {
            $front = Yaml::parse($parts[1]);
        } catch (\Throwable $e) {
            Log::warning('board.persona_frontmatter_invalid', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($front) || ! isset($front['slug'], $front['seat'])) {
            return null;
        }

        return [
            'slug' => (string) $front['slug'],
            'display_name' => (string) ($front['display_name'] ?? $front['slug']),
            'seat' => (string) $front['seat'],
            'archetype' => (string) ($front['archetype'] ?? 'archetype'),
            'likeness_mode' => (string) ($front['likeness_mode'] ?? 'archetype'),
            'inspired_by' => isset($front['inspired_by']) ? (string) $front['inspired_by'] : null,
            'extends' => isset($front['extends']) ? (string) $front['extends'] : null,
            'tenant_visible' => (bool) ($front['tenant_visible'] ?? true),
            'requires_flag' => isset($front['requires_flag']) && $front['requires_flag'] !== null
                ? (string) $front['requires_flag'] : null,
            'order' => (int) ($front['order'] ?? 999),
            'voice_persona' => isset($front['voice_persona']) && $front['voice_persona'] !== null
                ? (string) $front['voice_persona'] : null,
            'brain_scopes' => array_values(array_map('strval', (array) ($front['brain_scopes'] ?? []))),
            'stance_priors' => array_values(array_map('strval', (array) ($front['stance_priors'] ?? []))),
            'question_style' => array_values(array_map('strval', (array) ($front['question_style'] ?? []))),
            'kill_criteria_style' => (string) ($front['kill_criteria_style'] ?? ''),
            // Names and phrases that must not survive into an anonymised round.
            'redact_terms' => array_values(array_map('strval', (array) ($front['redact_terms'] ?? []))),
            'body' => trim((string) ($parts[2] ?? '')),
            'path' => $path,
        ];
    }
}
