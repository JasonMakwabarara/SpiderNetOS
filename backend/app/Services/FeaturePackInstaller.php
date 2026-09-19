<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeaturePack;
use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs a feature pack for a tenant (shared by CLI and HTTP API).
 */
class FeaturePackInstaller
{
    public function install(Tenant $tenant, string $packId, bool $force = false): array
    {
        $id = strtolower(trim($packId));
        if ($id === '') {
            throw new \InvalidArgumentException('pack_id is required.');
        }

        $src = $this->packsRoot().'/'.$id;

        if (! is_dir($src)) {
            throw new \RuntimeException("Pack directory not found: {$id}");
        }

        $manifestPath = $src.'/pack.yaml';
        if (! is_readable($manifestPath)) {
            throw new \RuntimeException("Missing pack.yaml for pack: {$id}");
        }

        $destination = storage_path('app/feature-packs/'.$id);
        if (File::isDirectory($destination) && ! $force) {
            // Already staged — continue with DB registration
        } else {
            if (File::isDirectory($destination)) {
                File::deleteDirectory($destination);
            }
            File::ensureDirectoryExists($destination);
            File::copyDirectory($src, $destination);
        }

        $manifest = Yaml::parseFile($manifestPath);
        $meta = $manifest['metadata'] ?? [];

        $this->verifySignature($manifest, $id);
        $this->assertEntitled($tenant, $id, $manifest);

        $pack = FeaturePack::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'pack_id' => $id,
            ],
            [
                'version' => $meta['version'] ?? '0.0.0',
                'vertical' => $meta['vertical'] ?? 'general',
                'display_name' => $meta['displayName'] ?? $meta['display_name'] ?? $id,
                'description' => $meta['description'] ?? null,
                'manifest' => $manifest,
                'status' => 'installed',
                'installed_at' => now(),
            ]
        );

        $agentsProvisioned = $this->provisionPackAgents($tenant, $manifest, $id);

        return [
            'pack_id' => $id,
            'version' => $pack->version,
            'display_name' => $pack->display_name,
            'status' => $pack->status,
            'agents_provisioned' => $agentsProvisioned,
            'entry_path' => $this->entryPathForPack($id),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws EntitlementRequiredException
     */
    private function assertEntitled(Tenant $tenant, string $packId, array $manifest): void
    {
        $pricing = $manifest['spec']['pricing'] ?? null;
        if (! $pricing) {
            return; // Free pack — no entitlement required.
        }

        $hasActiveEntitlement = PackEntitlement::forTenant($tenant->id)
            ->where('pack_id', $packId)
            ->active()
            ->exists();

        if ($hasActiveEntitlement) {
            return;
        }

        // Covered by the tenant's plan (included pack slots) → auto-grant an
        // included entitlement instead of demanding a separate purchase.
        if (app(PlanEntitlementService::class)->packIncluded($tenant->id, $packId)) {
            PackEntitlement::firstOrCreate(
                ['tenant_id' => $tenant->id, 'pack_id' => $packId, 'status' => 'active'],
                ['source' => 'included_in_plan', 'provider' => 'plan', 'amount_cents' => 0, 'purchased_at' => now()],
            );

            return;
        }

        $amountCents = (int) round((float) ($pricing['amount'] ?? 0) * 100);
        throw new EntitlementRequiredException($packId, $amountCents, (string) ($pricing['currency'] ?? 'USD'));
    }

    /**
     * Ed25519 signature verification (RFC 8032) over the canonicalised manifest
     * minus its signatures block, against a configured publisher key. Skipped
     * for unsigned/placeholder packs unless feature_packs.require_signed is on.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function verifySignature(array $manifest, string $packId): void
    {
        $requireSigned = (bool) config('feature_packs.require_signed', false);
        $block = $manifest['spec']['signatures'] ?? $manifest['signatures'] ?? null;

        $publisher = is_array($block) ? ($block['publisher'] ?? null) : null;
        $sig = is_array($block) ? ($block['signature'] ?? null) : null;
        $pubKey = $publisher ? config("feature_packs.publishers.{$publisher}") : null;

        $unverifiable = ! $block || ! $sig || ! $pubKey || $sig === 'placeholder-signature-to-be-generated';
        if ($unverifiable) {
            if ($requireSigned) {
                throw new \RuntimeException("Feature pack '{$packId}' is unsigned or its signature is not verifiable.");
            }

            return; // dev / unsigned allowed
        }

        $payload = $this->canonicalManifest($manifest);
        $ok = sodium_crypto_sign_verify_detached(base64_decode($sig, true) ?: '', $payload, base64_decode($pubKey, true) ?: '');
        if (! $ok) {
            throw new \RuntimeException("Feature pack '{$packId}' signature verification failed.");
        }
    }

    /** Deterministic bytes signed by publishers: manifest sans signatures, keys sorted. */
    private function canonicalManifest(array $manifest): string
    {
        unset($manifest['signatures']);
        if (isset($manifest['spec']['signatures'])) {
            unset($manifest['spec']['signatures']);
        }
        $sort = function (&$node) use (&$sort) {
            if (is_array($node)) {
                ksort($node);
                foreach ($node as &$v) {
                    $sort($v);
                }
            }
        };
        $sort($manifest);

        return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function entryPathForPack(string $packId): string
    {
        return match ($packId) {
            'financial-services' => '/financial',
            'sales-crm' => '/sales',
            'compliance-radar' => '/compliance',
            default => '/feature-packs',
        };
    }

    private function provisionPackAgents(Tenant $tenant, array $manifest, string $packId): int
    {
        $agents = $manifest['spec']['provides']['dynamic_agents'] ?? [];
        $count = 0;

        foreach ($agents as $agentDef) {
            $agentId = $agentDef['id'] ?? null;
            if (! $agentId) {
                continue;
            }

            // Namespaced per docs/feature-packs/SPEC.md isolation rule #3, so
            // packs that reuse common agent ids (e.g. "growth", "crm") never
            // collide when a tenant installs more than one pack.
            $slug = Str::slug($packId, '_').'_'.Str::slug((string) $agentId, '_');

            $config = ['pack_id' => $packId];
            $systemPrompt = $this->packAgentPrompt($packId, (string) $agentId);
            if ($systemPrompt !== null) {
                $config['system_prompt'] = $systemPrompt;
            }

            $existing = DB::table('agents')
                ->where('tenant_id', $tenant->id)
                ->where('slug', $slug)
                ->first(['id', 'config']);

            if ($existing) {
                // Backfill the pack prompt onto agents provisioned before the
                // pack shipped prompts/ — never overwrite a prompt already set.
                if ($systemPrompt !== null) {
                    $existingConfig = json_decode((string) $existing->config, true);
                    $existingConfig = is_array($existingConfig) ? $existingConfig : [];
                    if (empty($existingConfig['system_prompt'])) {
                        $existingConfig['system_prompt'] = $systemPrompt;
                        DB::table('agents')->where('id', $existing->id)->update([
                            'config' => json_encode($existingConfig),
                            'updated_at' => now(),
                        ]);
                    }
                }

                continue;
            }

            DB::table('agents')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'name' => $agentDef['displayName'] ?? $agentDef['name'] ?? $agentId,
                'slug' => $slug,
                'description' => "Provisioned from {$packId} pack",
                'type' => 'dynamic',
                'status' => 'inactive',
                'capabilities' => json_encode($agentDef['capabilities'] ?? []),
                'config' => json_encode($config),
                'activated_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Contents of the pack's prompts/{agent}.md, if the pack ships one.
     * Checked against both the staged storage copy and the source tree, and
     * tolerant of hyphen/underscore differences between the manifest agent id
     * and the prompt filename (e.g. `funnel_architect` -> funnel-architect.md).
     */
    private function packAgentPrompt(string $packId, string $agentId): ?string
    {
        $candidates = array_unique(array_filter([
            $agentId,
            Str::slug($agentId),        // funnel_architect -> funnel-architect
            Str::slug($agentId, '_'),   // funnel-architect -> funnel_architect
        ]));

        $roots = [
            storage_path('app/feature-packs/'.$packId),
            $this->packsRoot().'/'.$packId,
        ];

        foreach ($roots as $root) {
            foreach ($candidates as $candidate) {
                $path = $root.'/prompts/'.$candidate.'.md';
                if (is_readable($path)) {
                    $contents = trim((string) file_get_contents($path));
                    if ($contents !== '') {
                        return $contents;
                    }
                }
            }
        }

        return null;
    }

    private function packsRoot(): string
    {
        return rtrim((string) config('feature_packs.root'), '/');
    }
}
