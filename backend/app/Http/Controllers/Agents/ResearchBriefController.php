<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Models\AgentArtifact;
use App\Services\Research\ResearchBriefEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-scoped research drafts. Creating one never sets a tenant from
 * the body and never marks the draft reviewed. Download returns the
 * stored Markdown and does not call a model or fetch sources.
 */
class ResearchBriefController extends AgentsController
{
    public function index(Request $request): JsonResponse
    {
        $page = AgentArtifact::forTenant($this->tenantId($request))
            ->where('kind', AgentArtifact::KIND_RESEARCH_BRIEF)
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, (int) $request->query('per_page', 20))));

        $page->getCollection()->transform(fn (AgentArtifact $artifact) => $this->artifactPayload($artifact, false));

        return response()->json($page);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $artifact = $this->findBrief($request, $id);
        if ($artifact === null) {
            return response()->json(['error' => 'research_brief_not_found'], 404);
        }

        return response()->json(['data' => $this->artifactPayload($artifact)]);
    }

    public function store(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > ResearchBriefEnvelope::MAX_BYTES) {
            throw ValidationException::withMessages([
                'body' => ['Research brief exceeds 1 MB.'],
            ]);
        }

        $brief = ResearchBriefEnvelope::accept($request->all());
        $artifact = AgentArtifact::create([
            'tenant_id' => $this->tenantId($request),
            'kind' => AgentArtifact::KIND_RESEARCH_BRIEF,
            'title' => $brief['title'],
            'content' => $brief['markdown'],
            'meta' => $brief['meta'],
            'status' => AgentArtifact::STATUS_DRAFT,
        ]);
        $artifact->path = 'research-briefs/'.$artifact->id.'.md';
        $artifact->save();

        return response()->json(['data' => $this->artifactPayload($artifact)], 201);
    }

    public function markdown(Request $request, string $id): JsonResponse|Response
    {
        $artifact = $this->findBrief($request, $id);
        if ($artifact === null) {
            return response()->json(['error' => 'research_brief_not_found'], 404);
        }

        $filename = Str::slug((string) $artifact->title) ?: 'research-brief';

        return response((string) $artifact->content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.md"',
        ]);
    }

    private function findBrief(Request $request, string $id): ?AgentArtifact
    {
        $artifact = $this->findArtifact($request, $id);
        if ($artifact === null || $artifact->kind !== AgentArtifact::KIND_RESEARCH_BRIEF) {
            return null;
        }

        return $artifact;
    }
}
