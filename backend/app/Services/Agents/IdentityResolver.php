<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * packages/skills/identities.yaml: identity key (growth, crm, richard...)
 * -> agents.slug for the tenant, the core character it reports to, and the
 * skills it owns. Cached per manifest path for the process lifetime.
 */
final class IdentityResolver
{
    /** @var array<string, array<string, mixed>> keyed by manifest path */
    private static array $cache = [];

    /** @return array<string, mixed> parsed manifest (`identities`, `core_characters`) */
    public static function manifest(): array
    {
        $path = (string) config('agents.identities_manifest', '');
        if ($path === '') {
            return [];
        }
        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        $parsed = [];
        if (is_readable($path)) {
            try {
                $parsed = (array) Yaml::parseFile($path);
            } catch (\Throwable $e) {
                Log::warning('identities manifest unreadable', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        return self::$cache[$path] = $parsed;
    }

    /** @return array<string, array<string, mixed>> */
    public static function identities(): array
    {
        return (array) (self::manifest()['identities'] ?? []);
    }

    /** @return list<string> */
    public static function coreCharacters(): array
    {
        $chars = array_keys((array) (self::manifest()['core_characters'] ?? []));

        return $chars !== [] ? array_map('strval', $chars) : array_values((array) config('agents.core_characters', []));
    }

    /** agents.slug that runs an identity (core characters use their bare slug). */
    public static function agentSlugFor(string $identityKey): ?string
    {
        $identity = self::identities()[$identityKey] ?? null;
        if (is_array($identity)) {
            $slug = $identity['agents_slug'] ?? null;

            return is_string($slug) && $slug !== '' ? $slug : null;
        }

        return in_array($identityKey, self::coreCharacters(), true) ? $identityKey : null;
    }

    public static function identityForAgentSlug(string $agentSlug): ?string
    {
        foreach (self::identities() as $key => $identity) {
            if (is_array($identity) && ($identity['agents_slug'] ?? null) === $agentSlug) {
                return (string) $key;
            }
        }

        return in_array($agentSlug, self::coreCharacters(), true) ? $agentSlug : null;
    }

    /** @return list<string> */
    public static function skillsFor(string $identityKey): array
    {
        return array_values(array_map('strval', (array) (self::identities()[$identityKey]['skills'] ?? [])));
    }

    public static function identityForSkill(string $skillSlug): ?string
    {
        foreach (self::identities() as $key => $identity) {
            if (is_array($identity) && in_array($skillSlug, (array) ($identity['skills'] ?? []), true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** True when the identities manifest maps this agents.slug to the skill. */
    public static function agentOwnsSkill(string $agentSlug, string $skillSlug): bool
    {
        $identity = self::identityForAgentSlug($agentSlug);

        return $identity !== null && in_array($skillSlug, self::skillsFor($identity), true);
    }

    public static function reportsTo(string $identityKey): ?string
    {
        $to = self::identities()[$identityKey]['reports_to'] ?? null;

        return is_string($to) ? $to : (in_array($identityKey, self::coreCharacters(), true) ? $identityKey : null);
    }

    /** agents.id for the identity in this tenant, or null when the row has not been provisioned. */
    public static function resolveAgentId(string $tenantId, string $identityKey): ?string
    {
        $slug = self::agentSlugFor($identityKey);
        if ($slug === null) {
            return null;
        }

        $id = DB::table('agents')->where('tenant_id', $tenantId)->where('slug', $slug)->value('id');

        return $id ? (string) $id : null;
    }

    public static function reset(): void
    {
        self::$cache = [];
    }
}
