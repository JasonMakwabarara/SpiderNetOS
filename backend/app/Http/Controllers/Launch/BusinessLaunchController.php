<?php

declare(strict_types=1);

namespace App\Http\Controllers\Launch;

use App\Http\Controllers\Controller;
use App\Models\BusinessLaunch;
use App\Services\FeatureFlag;
use App\Services\Launch\BusinessLaunchService;
use App\Services\Launch\JurisdictionPack;
use App\Services\Launch\LaunchArtefacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Atlas, I want to start a business" — /api/launch/* (plan D7 §5).
 *
 *   GET  /launch                              current state + next question
 *   POST /launch/start           {jurisdiction?}
 *   POST /launch/answer          {question_id?, answer}
 *   POST /launch/stages/{stage}/commit
 *   POST /launch/generate        {targets: [research|finance|plan]}
 *   GET  /launch/deliverables
 *   POST /launch/submit                       business_plan approval
 *   GET  /launch/jurisdictions/{code}/checklist
 *
 * Every uuid lookup is guarded by Str::isUuid() before it reaches a uuid
 * column — Postgres raises `invalid input syntax for type uuid` and aborts
 * the surrounding transaction where sqlite just finds nothing (the same
 * pattern as Agents\AgentsController::findRun()).
 *
 * Nothing returned here is legal or financial advice; every payload carries
 * the pack's disclaimer.
 */
class BusinessLaunchController extends Controller
{
    public function __construct(
        private readonly BusinessLaunchService $launches,
        private readonly JurisdictionPack $jurisdictions,
        private readonly LaunchArtefacts $artefacts,
    ) {}

    /** GET /api/launch */
    public function show(Request $request): JsonResponse
    {
        if ($gate = $this->gate($request)) {
            return $gate;
        }

        $launch = $this->find($request);
        if ($launch === null) {
            return response()->json([
                'data' => [
                    'status' => null,
                    'started' => false,
                    'jurisdictions' => $this->jurisdictions->codes(),
                    'disclaimer' => JurisdictionPack::DISCLAIMER,
                ],
            ]);
        }

        return response()->json(['data' => $this->launches->state($launch) + ['started' => true]]);
    }

    /** POST /api/launch/start */
    public function start(Request $request): JsonResponse
    {
        if ($gate = $this->gate($request)) {
            return $gate;
        }

        $data = $request->validate([
            'jurisdiction' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $launch = $this->launches->start($this->tenantId($request), $data['jurisdiction'] ?? null);

        return response()->json(['data' => $this->launches->state($launch) + ['started' => true]], 201);
    }

    /** POST /api/launch/answer */
    public function answer(Request $request): JsonResponse
    {
        if ($gate = $this->gate($request)) {
            return $gate;
        }

        $data = $request->validate([
            'question_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'answer' => ['present', 'nullable', 'string', 'max:4000'],
            'skip' => ['sometimes', 'boolean'],
        ]);

        $launch = $this->launches->start($this->tenantId($request));
        $result = $this->launches->answer(
            $launch,
            (string) ($data['answer'] ?? ''),
            $data['question_id'] ?? null,
            (bool) ($data['skip'] ?? false),
        );

        if (($result['recorded'] ?? false) === false && ($result['reason'] ?? '') === 'unknown_question') {
            return response()->json(['message' => 'That question is not part of this interview.'], 422);
        }

        return response()->json(['data' => $result]);
    }

    /** POST /api/launch/stages/{stage}/commit */
    public function commitStage(Request $request, string $stage): JsonResponse
    {
        if ($gate = $this->gate($request)) {
            return $gate;
        }

        $launch = $this->find($request);
        if ($launch === null) {
            return $this->notStarted();
        }

        $result = $this->launches->commitStage($launch, $stage);
        if (($result['reason'] ?? null) === 'unknown_stage') {
            return response()->json(['message' => 'No such launch stage.'], 404);
        }
        if (! $result['committed']) {
            return response()->json([
                'message' => 'This stage still needs answers before it can be committed.',
                'missing' => $result['missing'] ?? [],
            ], 422);
        }

        return response()->json(['data' => $result]);
    }

    /** POST /api/launch/generate */
    public function generate(Request $request): JsonResponse
    {
        if ($gate = $this->gate($request)) {
            return $gate;
        }

        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['string', 'in:'.implode(',', LaunchArtefacts::TARGETS)],
        ]);

        $launch = $this->find($request);
        if ($launch === null) {
            return $this->notStarted();
        }

        return response()->json(['data' => $this->launches->generate($launch, $data['targets'])]);
    }

    /** GET /api/launch/deliverables */
    public function deliverables(Request $request): JsonResponse
    {
        if ($gate = $this->gate($request)) {
            return $gate;
        }

        $launch = $this->find($request);
        if ($launch === null) {
            return response()->json(['data' => [], 'disclaimer' => JurisdictionPack::DISCLAIMER]);
        }

        return response()->json([
            'data' => $this->artefacts->deliverables($launch),
            'disclaimer' => JurisdictionPack::DISCLAIMER,
        ]);
    }

    /** POST /api/launch/submit — creates the `business_plan` approval. */
    public function submit(Request $request): JsonResponse
    {
        if ($gate = $this->gate($request)) {
            return $gate;
        }

        $launch = $this->find($request);
        if ($launch === null) {
            return $this->notStarted();
        }

        $result = $this->launches->submit($launch, (string) $request->user()->id);
        if (! ($result['submitted'] ?? false)) {
            return response()->json([
                'message' => 'Generate the business plan before submitting it for approval.',
                'reason' => $result['reason'] ?? 'no_plan',
            ], 422);
        }

        return response()->json(['data' => $result], 201);
    }

    /** GET /api/launch/jurisdictions/{code}/checklist */
    public function checklist(Request $request, string $code): JsonResponse
    {
        if ($gate = $this->gate($request)) {
            return $gate;
        }

        $launch = $this->find($request);
        $checklist = $launch !== null
            ? $this->launches->checklist($launch, $code)
            : $this->jurisdictions->checklist($code);

        if ($checklist === null) {
            return response()->json(['message' => 'No jurisdiction pack for that country.'], 404);
        }

        return response()->json(['data' => $checklist]);
    }

    // -----------------------------------------------------------------------

    private function find(Request $request): ?BusinessLaunch
    {
        return $this->launches->find($this->tenantId($request));
    }

    private function tenantId(Request $request): string
    {
        $tenant = $request->attributes->get('tenant');

        return (string) ($tenant?->id ?? $request->attributes->get('tenant_id'));
    }

    private function gate(Request $request): ?JsonResponse
    {
        $tenantId = $this->tenantId($request);

        // business_launches.tenant_id is a uuid column.
        if ($tenantId === '' || ! Str::isUuid($tenantId)) {
            return response()->json(['message' => 'Tenant not resolved.'], 403);
        }
        if (! FeatureFlag::on('launch.enabled', $tenantId)) {
            return response()->json(['message' => 'Starting a business with Atlas is not enabled for this workspace.'], 404);
        }

        return null;
    }

    private function notStarted(): JsonResponse
    {
        return response()->json(['message' => 'No business launch has been started yet.'], 404);
    }
}
