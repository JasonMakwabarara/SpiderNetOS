<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\RevertBrainFileRequest;
use App\Http\Requests\Brain\UpdateBrainFileRequest;
use App\Models\BrainFile;
use App\Models\BrainFileVersion;
use App\Models\BrainProposal;
use App\Services\Brain\BrainConflictException;
use App\Services\Brain\BrainGapAnalyzer;
use App\Services\Brain\BrainManifest;
use App\Services\Brain\BrainMarkdown;
use App\Services\Brain\BrainStore;
use App\Services\Brain\BrainSyncService;
use App\Services\FeatureFlag;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * /api/brain/* — the Knowledge brain for the cockpit (plan D2). Runs inside
 * the protected tenant group; the tenant is the one ResolveTenant attached
 * to the request. Every response is {data: ...}; a stale PUT is 409
 * {current_version}; an unknown path is 404.
 */
class BrainController extends Controller
{
    public function __construct(
        private readonly BrainStore $store,
        private readonly BrainManifest $manifest,
        private readonly BrainGapAnalyzer $gaps,
        private readonly BrainSyncService $sync,
    ) {}

    public function tree(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        return response()->json(['data' => $this->store->tree($tenantId)]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        return response()->json(['data' => $this->gaps->readiness($tenantId)]);
    }

    /** GET /brain/gaps?skill=&requires[]= — requires[] accepts manifest keys, `path#section`, or paths. */
    public function gaps(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $requires = [];
        foreach ((array) $request->query('requires', []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $requires[] = trim($item);
            }
        }

        $skill = trim((string) $request->query('skill', ''));
        if ($skill !== '') {
            $requires = array_merge($requires, self::requiresForSkill($skill));
        }
        if ($requires === []) {
            $requires = array_map(static fn (string $path) => ['path' => $path], $this->manifest->readinessOrder());
        }

        try {
            $gaps = $this->gaps->gaps($tenantId, $requires);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $gaps, 'meta' => ['skill' => $skill !== '' ? $skill : null, 'requires' => $requires]]);
    }

    public function show(Request $request, string $path): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }
        $path = BrainStore::normalizePath($path);
        if (! BrainStore::isValidPath($path)) {
            return $this->notFound();
        }

        $version = $request->query('version');
        $file = $this->store->read($tenantId, $path, $version !== null && $version !== '' ? (int) $version : null);
        if (! $file) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->present($file)]);
    }

    public function update(UpdateBrainFileRequest $request, string $path): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }
        $path = BrainStore::normalizePath($path);
        if (! BrainStore::isValidPath($path)) {
            return response()->json(['message' => 'Invalid brain path.'], 422);
        }
        if ($this->manifest->isAgentPrivate($path)) {
            return response()->json(['message' => 'Agent workspaces are read-only in the brain view.'], 403);
        }

        $existing = $this->store->read($tenantId, $path);
        $existed = $existing !== null;
        // PUT without a `frontmatter` key keeps the structured facts as they are.
        $frontmatter = $request->has('frontmatter') ? $request->frontmatterInput() : (array) ($existing?->frontmatter ?? []);

        try {
            $file = $this->store->write(
                $tenantId,
                $path,
                (string) $request->input('content', ''),
                $frontmatter,
                BrainFile::SOURCE_HUMAN,
                (string) optional($request->user())->id,
                $request->baseVersion(),
                null,
                $request->input('change_note'),
            );
        } catch (BrainConflictException $e) {
            return response()->json([
                'message' => 'The file changed since you loaded it. Reload and re-apply your edit.',
                'current_version' => $e->currentVersion(),
                'base_version' => $e->baseVersion,
            ], 409);
        }

        return response()->json(['data' => $this->present($file)], $existed ? 200 : 201);
    }

    public function destroy(Request $request, string $path): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }
        $path = BrainStore::normalizePath($path);
        if (! BrainStore::isValidPath($path) || $this->store->read($tenantId, $path) === null) {
            return $this->notFound();
        }
        if ($this->manifest->isAgentPrivate($path)) {
            return response()->json(['message' => 'Agent workspaces are read-only in the brain view.'], 403);
        }

        $this->store->delete($tenantId, $path);

        return response()->json(['data' => ['path' => $path, 'deleted' => true]]);
    }

    public function versions(Request $request, string $path): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }
        $path = BrainStore::normalizePath($path);
        if (! BrainStore::isValidPath($path) || $this->store->read($tenantId, $path) === null) {
            return $this->notFound();
        }

        $versions = $this->store->versions($tenantId, $path)->map(static fn (BrainFileVersion $v) => [
            'version' => (int) $v->version,
            'source' => $v->source,
            'author_type' => $v->author_type,
            'author_ref' => $v->author_ref,
            'change_summary' => $v->change_summary,
            'content_hash' => $v->content_hash,
            'size' => strlen((string) $v->content),
            'created_at' => $v->created_at?->toIso8601String(),
        ])->values();

        return response()->json(['data' => $versions]);
    }

    public function revert(RevertBrainFileRequest $request, string $path): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }
        $path = BrainStore::normalizePath($path);
        if (! BrainStore::isValidPath($path)) {
            return $this->notFound();
        }

        try {
            $file = $this->store->revert($tenantId, $path, (int) $request->input('version'), (string) optional($request->user())->id);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->present($file)]);
    }

    public function sync(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $written = $this->sync->syncAll($tenantId);

        return response()->json(['data' => ['written' => $written, 'synced_at' => now()->toIso8601String()]]);
    }

    /** GET /brain/search?q= — LIKE over title/path/content; pgvector retrieval lands with BrainRetriever. */
    public function search(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => [], 'meta' => ['q' => $q]]);
        }
        $limit = min(50, max(1, (int) $request->query('limit', 20)));
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $files = BrainFile::forTenant($tenantId)
            ->where(fn ($w) => $w->where('content', 'like', $term)->orWhere('title', 'like', $term)->orWhere('path', 'like', $term))
            ->orderBy('path')
            ->limit($limit)
            ->get();

        $hits = [];
        foreach ($files as $file) {
            if ($this->manifest->isAgentPrivate($file->path)) {
                continue;
            }
            [$section, $snippet] = self::locate((string) $file->content, $q);
            $hits[] = [
                'path' => $file->path,
                'title' => $file->title,
                'version' => (int) $file->version,
                'data_class' => $file->data_class,
                'section' => $section,
                'snippet' => $snippet,
            ];
        }

        return response()->json(['data' => $hits, 'meta' => ['q' => $q, 'mode' => 'like']]);
    }

    public function proposals(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (($gate = $this->gate($tenantId)) !== null) {
            return $gate;
        }

        $query = BrainProposal::forTenant($tenantId)->orderByDesc('created_at');
        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $query->whereIn('status', array_filter(explode(',', $status)));
        } else {
            $query->open();
        }
        if ($request->filled('path')) {
            $query->where('path', BrainStore::normalizePath((string) $request->query('path')));
        }

        return response()->json(['data' => $query->limit(100)->get()]);
    }

    // -------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function present(BrainFile $file): array
    {
        $spec = $this->manifest->fileSpec($file->path);

        return [
            'path' => $file->path,
            'title' => $file->title,
            'content' => (string) $file->content,
            'frontmatter' => (array) ($file->frontmatter ?? []),
            'source' => $file->source,
            'managed' => (bool) $file->managed,
            'data_class' => $file->data_class,
            'version' => (int) $file->version,
            'content_hash' => $file->content_hash,
            'status' => BrainGapAnalyzer::statusFor((string) $file->content, $spec),
            'sections' => array_keys(BrainMarkdown::sections((string) $file->content)),
            'updated_at' => $file->updated_at?->toIso8601String(),
            'spec' => $spec === null ? null : [
                'title' => $this->manifest->title($file->path),
                'data_class' => $this->manifest->dataClass($file->path),
                'source' => $this->manifest->source($file->path),
                'required_sections' => $this->manifest->requiredSections($file->path),
                'frontmatter' => $this->manifest->frontmatterKeys($file->path),
                'stale_after_days' => $this->manifest->staleAfterDays($file->path),
            ],
        ];
    }

    /**
     * Section + snippet around the first match, for the search result list.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function locate(string $content, string $q): array
    {
        $section = null;
        foreach (BrainMarkdown::sections($content) as $name => $body) {
            if (mb_stripos($body, $q) !== false) {
                $section = (string) $name;
                break;
            }
        }
        $pos = mb_stripos($content, $q);
        if ($pos === false) {
            return [$section, mb_substr(trim($content), 0, 160)];
        }
        $start = max(0, $pos - 80);
        $snippet = trim((string) preg_replace('/\s+/u', ' ', mb_substr($content, $start, 240)));

        return [$section, ($start > 0 ? '…' : '').$snippet.'…'];
    }

    /**
     * `brain.requires` from packages/skills/<slug>/card.yaml when the card
     * exists; the skills stream owns the registry, this is a read-only peek.
     *
     * @return list<array<string, mixed>>
     */
    public static function requiresForSkill(string $slug): array
    {
        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            return [];
        }
        $root = rtrim((string) config('agents.skills_root', ''), '/');
        $card = $root.'/'.$slug.'/card.yaml';
        if ($root === '' || ! is_readable($card)) {
            return [];
        }
        try {
            $parsed = Yaml::parseFile($card);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter((array) ($parsed['brain']['requires'] ?? []), 'is_array'));
    }

    private function tenantId(Request $request): string
    {
        $tenant = $request->attributes->get('tenant');

        return (string) ($tenant?->id ?? $request->attributes->get('tenant_id'));
    }

    private function gate(string $tenantId): ?JsonResponse
    {
        if ($tenantId === '') {
            return response()->json(['message' => 'Tenant not resolved.'], 403);
        }
        if (! FeatureFlag::on('brain.enabled', $tenantId)) {
            return response()->json(['message' => 'The Knowledge brain is not enabled for this tenant.'], 404);
        }

        return null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Brain file not found.'], 404);
    }
}
