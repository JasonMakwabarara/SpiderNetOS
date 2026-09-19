<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\BrainFile;
use Illuminate\Support\Facades\Log;

/**
 * Lazy access to the classes other program streams provide (Brain, Skills,
 * Revisions, the circuit breaker). Everything is resolved through the
 * container by name and called duck-typed so that:
 *
 *   - the runtime compiles and boots before those streams land;
 *   - tests can bind a minimal double under the real class name
 *     (`app()->instance(Collaborators::SKILL_REGISTRY, $fake)`) whether or
 *     not the real class exists;
 *   - once the real class ships it is used untouched.
 *
 * The shared contract (plan PR 1, "shared contract") is documented per
 * constant. Nothing here type-hints the foreign classes on purpose.
 */
final class Collaborators
{
    /** SkillRegistry::get(string $slug): ?SkillCard */
    public const SKILL_REGISTRY = 'App\Services\Skills\SkillRegistry';

    /** SkillPromptBuilder::build(SkillCard, BrainSnapshot, array $inputs, array $context = []): array{system, prompt, version} */
    public const SKILL_PROMPT_BUILDER = 'App\Services\Skills\SkillPromptBuilder';

    /** SkillOutputValidator::validate(SkillCard, string $raw, array $facts = []): ValidationResult{ok, data, errors, repaired} */
    public const SKILL_OUTPUT_VALIDATOR = 'App\Services\Skills\SkillOutputValidator';

    /** SkillInstaller::installForTenant(string $tenantId, string $slug, ?string $agentSlug = null, string $installedFrom = 'catalogue'): TenantSkill */
    public const SKILL_INSTALLER = 'App\Services\Skills\SkillInstaller';

    /** BrainSnapshot::capture(string $tenantId, array $paths = []): self; ->readAt(path); ->toArray(); ::fromArray(tenantId, map); ->hash() */
    public const BRAIN_SNAPSHOT = 'App\Services\Brain\BrainSnapshot';

    /** BrainGapAnalyzer::gaps(string $tenantId, array $requires): array<{path, section, question, reason}> */
    public const BRAIN_GAP_ANALYZER = 'App\Services\Brain\BrainGapAnalyzer';

    /** BrainStore::upsertSection(tenantId, path, heading, body, source = 'human', changedBy = null); ->read(...) */
    public const BRAIN_STORE = 'App\Services\Brain\BrainStore';

    /** BrainSyncService::syncIfStale(string $tenantId) */
    public const BRAIN_SYNC = 'App\Services\Brain\BrainSyncService';

    /** RevisionRecorder::record(tenantId, subjectType, subjectId, original, edited, userId = null, meta = []) */
    public const REVISION_RECORDER = 'App\Services\Revisions\RevisionRecorder';

    /** AgentCircuitBreaker::isPaused(tenantId, agentId = null, skillSlug = null, toolRisk = null): ?string */
    public const CIRCUIT_BREAKER = 'App\Services\Agents\AgentCircuitBreaker';

    /** Tool risks that must refuse when the breaker cannot be consulted. */
    private const FAIL_CLOSED_RISKS = ['send', 'irreversible'];

    public static function has(string $class): bool
    {
        return app()->bound($class) || class_exists($class);
    }

    public static function resolve(string $class): ?object
    {
        if (! self::has($class)) {
            return null;
        }

        try {
            $instance = app($class);
        } catch (\Throwable $e) {
            Log::warning('agents collaborator could not be resolved', ['class' => $class, 'error' => $e->getMessage()]);

            return null;
        }

        return is_object($instance) ? $instance : null;
    }

    /** Concrete class name to use for static calls (a bound double wins over the autoloaded class). */
    public static function className(string $class): ?string
    {
        if (app()->bound($class)) {
            $instance = app($class);

            return is_object($instance) ? get_class($instance) : null;
        }

        return class_exists($class) ? $class : null;
    }

    public static function skillRegistry(): ?object
    {
        return self::resolve(self::SKILL_REGISTRY);
    }

    public static function promptBuilder(): ?object
    {
        return self::resolve(self::SKILL_PROMPT_BUILDER);
    }

    public static function outputValidator(): ?object
    {
        return self::resolve(self::SKILL_OUTPUT_VALIDATOR);
    }

    public static function skillInstaller(): ?object
    {
        return self::resolve(self::SKILL_INSTALLER);
    }

    public static function gapAnalyzer(): ?object
    {
        return self::resolve(self::BRAIN_GAP_ANALYZER);
    }

    public static function brainStore(): ?object
    {
        return self::resolve(self::BRAIN_STORE);
    }

    public static function brainSync(): ?object
    {
        return self::resolve(self::BRAIN_SYNC);
    }

    public static function revisionRecorder(): ?object
    {
        return self::resolve(self::REVISION_RECORDER);
    }

    /** @param list<string> $paths */
    public static function captureSnapshot(string $tenantId, array $paths = []): ?object
    {
        $class = self::className(self::BRAIN_SNAPSHOT);
        if ($class === null) {
            return null;
        }

        return $class::capture($tenantId, $paths);
    }

    /** @param array<string, mixed> $map */
    public static function snapshotFromArray(string $tenantId, array $map): ?object
    {
        $class = self::className(self::BRAIN_SNAPSHOT);
        if ($class === null) {
            return null;
        }

        return $class::fromArray($tenantId, $map);
    }

    /**
     * Gap questions for a card's brain.requires (empty when the analyzer is
     * not available yet — the runtime then trusts the card).
     *
     * @param  list<array<string, mixed>>  $requires
     * @return list<array{path: string, section: ?string, question: ?string, reason: ?string}>
     */
    public static function gaps(string $tenantId, array $requires): array
    {
        $analyzer = self::gapAnalyzer();
        if ($analyzer === null || $requires === []) {
            return [];
        }

        $gaps = $analyzer->gaps($tenantId, $requires);
        if (! is_iterable($gaps)) {
            return [];
        }
        $gaps = is_array($gaps) ? $gaps : iterator_to_array($gaps);

        return array_values(array_map(static function ($gap): array {
            $gap = is_object($gap) ? get_object_vars($gap) : (array) $gap;

            return [
                'path' => (string) ($gap['path'] ?? ''),
                'section' => isset($gap['section']) ? (string) $gap['section'] : null,
                'question' => isset($gap['question']) ? (string) $gap['question'] : null,
                'reason' => isset($gap['reason']) ? (string) $gap['reason'] : null,
            ];
        }, $gaps));
    }

    /** Non-empty reason string when the circuit breaker pauses this scope; null otherwise. */
    public static function breakerReason(string $tenantId, ?string $agentId = null, ?string $skillSlug = null, ?string $toolRisk = null): ?string
    {
        $breaker = self::resolve(self::CIRCUIT_BREAKER);
        if ($breaker === null) {
            return self::unanswerable($toolRisk, 'the circuit breaker is not installed');
        }

        if (method_exists($breaker, 'available') && ! $breaker->available()) {
            return self::unanswerable($toolRisk, 'the circuit breaker store cannot be read');
        }

        try {
            $reason = $breaker->isPaused($tenantId, $agentId, $skillSlug, $toolRisk);
        } catch (\Throwable $e) {
            Log::warning('agent circuit breaker check failed', ['tenant_id' => $tenantId, 'tool_risk' => $toolRisk, 'error' => $e->getMessage()]);

            return self::unanswerable($toolRisk, 'the circuit breaker could not be read');
        }

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * What an unanswerable breaker check means, by risk.
     *
     * Reading and drafting degrade open: a database hiccup should not stop
     * someone being helped, and nothing has left the building. Sending and
     * irreversible actions degrade closed, because absence of evidence of
     * prohibition is not evidence of authorization — and a wrongly-sent
     * message cannot be unsent once the check comes back online.
     */
    private static function unanswerable(?string $toolRisk, string $why): ?string
    {
        if ($toolRisk === null || ! in_array($toolRisk, self::FAIL_CLOSED_RISKS, true)) {
            return null;
        }

        return $why.', so an action that leaves the building cannot be authorised';
    }

    /** Best-effort BrainSyncService::syncIfStale (never fails a run). */
    public static function syncBrainIfStale(string $tenantId): void
    {
        $sync = self::brainSync();
        if ($sync === null) {
            return;
        }

        try {
            $sync->syncIfStale($tenantId);
        } catch (\Throwable $e) {
            Log::warning('brain sync before run failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Read one brain path for a tenant: the pinned snapshot first, then the
     * brain_files head row.
     *
     * @return array{path: string, content: string, version: int, frontmatter: array<string, mixed>}|null
     */
    public static function readBrainPath(string $tenantId, string $path, ?object $snapshot = null): ?array
    {
        if ($snapshot !== null && method_exists($snapshot, 'readAt')) {
            $hit = $snapshot->readAt($path);
            if ($hit !== null) {
                $hit = is_object($hit) ? get_object_vars($hit) : (array) $hit;

                return [
                    'path' => $path,
                    'content' => (string) ($hit['content'] ?? ''),
                    'version' => (int) ($hit['version'] ?? 0),
                    'frontmatter' => (array) ($hit['frontmatter'] ?? []),
                ];
            }
        }

        $file = BrainFile::forTenant($tenantId)->where('path', $path)->first();
        if ($file === null) {
            return null;
        }

        return [
            'path' => $path,
            'content' => (string) $file->content,
            'version' => (int) $file->version,
            'frontmatter' => (array) ($file->frontmatter ?? []),
        ];
    }
}
