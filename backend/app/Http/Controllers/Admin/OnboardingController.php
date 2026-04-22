<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EventStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OnboardingController extends Controller
{
    private EventStore $eventStore;

    public function __construct(EventStore $eventStore)
    {
        $this->eventStore = $eventStore;
    }

    /** Enums: 5 persisted steps + 1 observation + schema version */
    private const PERSISTED_STEPS = ['tenant', 'budget', 'invites', 'strictness', 'branding'];
    private const OBSERVATION_STEPS = ['observability'];
    private const SCHEMA_VERSION = 1;

    /**
     * GET /api/admin/onboarding
     * Return current onboarding state and completion status
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        return response()->json([
            'onboarding' => $tenant->onboarding,
            'completed' => $tenant->onboarding_completed_at !== null,
            'automation_level' => $tenant->automation_level,
            'user_completed_at' => $user->onboarding_completed_at,
        ]);
    }

    /**
     * PUT /api/admin/onboarding
     * Persist one persisted-step payload
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'required|integer|in:1',
            'step' => 'required|string|in:' . implode(',', self::PERSISTED_STEPS),
            'data' => 'required|array',
        ]);

        $user = $request->user();
        $tenant = $user->tenant;

        // Validate step-specific data structure
        $stepValidation = $this->validateStepData($validated['step'], $validated['data']);
        if ($stepValidation !== null) {
            return response()->json(['error' => $stepValidation], 422);
        }

        // Merge with existing onboarding data, preserving _v
        $onboarding = $tenant->onboarding ?? [];
        $onboarding['_v'] = self::SCHEMA_VERSION;
        $onboarding[$validated['step']] = $validated['data'];

        // Persist atomically
        $tenant->update(['onboarding' => $onboarding]);

        // Compute structural hash for PII hygiene (keys only, sorted)
        $keys = array_keys($validated['data']);
        sort($keys);
        $keysHash = md5(implode(',', $keys));

        // Emit structural-only event (no PII in event_log)
        $this->eventStore->append(
            tenantId: $tenant->id,
            aggregateType: 'tenant_onboarding',
            aggregateId: $tenant->id,
            eventType: 'tenant.onboarding.step.persisted',
            payload: [
                'step' => $validated['step'],
                'field_count' => count($keys),
                'keys_hash' => $keysHash,
                'version' => self::SCHEMA_VERSION,
            ],
            metadata: [
                'user_id' => $user->id,
            ]
        );

        // Special handling: if step is 'strictness', also update automation_level
        if ($validated['step'] === 'strictness' && isset($validated['data']['automation_level'])) {
            $level = $validated['data']['automation_level'];
            if (in_array($level, ['manual', 'assisted', 'autonomous'], true)) {
                $tenant->update(['automation_level' => $level]);
            }
        }

        return response()->json([
            'status' => 'ok',
            'onboarding' => $onboarding,
        ]);
    }

    /**
     * POST /api/admin/onboarding/observe
     * Log an observation step (no mutation to onboarding jsonb)
     */
    public function observe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'required|integer|in:1',
            'step' => 'required|string|in:' . implode(',', self::OBSERVATION_STEPS),
        ]);

        $user = $request->user();
        $tenant = $user->tenant;

        // Intentionally do NOT mutate onboarding jsonb for observation steps
        // This preserves Markov integrity (observations ≠ transitions)

        // Emit observation event (intentionally unmapped in STE)
        $this->eventStore->append(
            tenantId: $tenant->id,
            aggregateType: 'tenant_onboarding',
            aggregateId: $tenant->id,
            eventType: 'tenant.onboarding.step.observed',
            payload: [
                'step' => $validated['step'],
                'version' => self::SCHEMA_VERSION,
            ],
            metadata: [
                'user_id' => $user->id,
            ]
        );

        return response()->json([
            'status' => 'observed',
            'step' => $validated['step'],
        ]);
    }

    /**
     * POST /api/admin/onboarding/complete
     * Finalize onboarding; requires all 5 persisted steps present
     */
    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        $onboarding = $tenant->onboarding ?? [];

        // Verify all persisted steps are present
        $missing = [];
        foreach (self::PERSISTED_STEPS as $step) {
            if (!isset($onboarding[$step])) {
                $missing[] = $step;
            }
        }

        if (!empty($missing)) {
            return response()->json([
                'error' => 'Missing required steps',
                'missing' => $missing,
            ], 422);
        }

        $startedAt = null;
        $elapsedMs = null;

        DB::transaction(function () use ($tenant, $user, $onboarding, &$startedAt, &$elapsedMs) {
            // Check if this is first-time completion
            $isFirstCompletion = $tenant->onboarding_completed_at === null;

            $now = now();
            $startedAt = $onboarding['tenant']['started_at'] ?? $tenant->created_at;
            $elapsedMs = (int) (($now->timestamp - strtotime($startedAt)) * 1000);

            // Update tenant
            $tenant->update([
                'onboarding_completed_at' => $now,
            ]);

            // Update user
            $user->update([
                'onboarding_completed_at' => $now,
            ]);

            // Emit "started" event if first completion (trial → onboarding_active)
            if ($isFirstCompletion) {
                $this->eventStore->append(
                    tenantId: $tenant->id,
                    aggregateType: 'tenant_lifecycle',
                    aggregateId: $tenant->id,
                    eventType: 'tenant.onboarding.started',
                    payload: [
                        'version' => self::SCHEMA_VERSION,
                    ],
                    metadata: [
                        'trigger' => 'onboarding.complete',
                        'user_id' => $user->id,
                    ]
                );
            }

            // Emit "completed" event (onboarding_active → active)
            $this->eventStore->append(
                tenantId: $tenant->id,
                aggregateType: 'tenant_lifecycle',
                aggregateId: $tenant->id,
                eventType: 'tenant.onboarding.completed',
                payload: [
                    'version' => self::SCHEMA_VERSION,
                    'elapsed_ms' => $elapsedMs,
                    'automation_level' => $tenant->automation_level,
                ],
                metadata: [
                    'user_id' => $user->id,
                ]
            );

            // Emit automation_level.set event for causal baseline
            $this->eventStore->append(
                tenantId: $tenant->id,
                aggregateType: 'tenant',
                aggregateId: $tenant->id,
                eventType: 'tenant.automation_level.set',
                payload: [
                    'level' => $tenant->automation_level,
                    'changed_by' => $user->id,
                    'version' => self::SCHEMA_VERSION,
                ],
                metadata: [
                    'source' => 'onboarding.complete',
                ]
            );
        });

        return response()->json([
            'status' => 'completed',
            'elapsed_ms' => $elapsedMs,
            'automation_level' => $tenant->automation_level,
        ]);
    }

    /**
     * PUT /api/admin/tenant/automation-level
     * Update automation level after onboarding (settings page)
     */
    public function updateAutomationLevel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'automation_level' => 'required|string|in:manual,assisted,autonomous',
        ]);

        $user = $request->user();
        $tenant = $user->tenant;

        $oldLevel = $tenant->automation_level;
        $newLevel = $validated['automation_level'];

        if ($oldLevel === $newLevel) {
            return response()->json(['status' => 'unchanged'], 204);
        }

        $tenant->update(['automation_level' => $newLevel]);

        // Emit change event
        $this->eventStore->append(
            tenantId: $tenant->id,
            aggregateType: 'tenant',
            aggregateId: $tenant->id,
            eventType: 'tenant.automation_level.set',
            payload: [
                'level' => $newLevel,
                'previous_level' => $oldLevel,
                'changed_by' => $user->id,
                'version' => self::SCHEMA_VERSION,
            ],
            metadata: [
                'source' => 'settings.update',
            ]
        );

        return response()->json([
            'status' => 'updated',
            'automation_level' => $newLevel,
        ]);
    }

    /**
     * Validate step-specific data structure
     * Returns null if valid, error string if invalid
     */
    private function validateStepData(string $step, array $data): ?string
    {
        switch ($step) {
            case 'tenant':
                if (empty($data['name'])) {
                    return 'tenant.name is required';
                }
                if (empty($data['industry'])) {
                    return 'tenant.industry is required';
                }
                if (empty($data['region'])) {
                    return 'tenant.region is required';
                }
                break;

            case 'budget':
                if (!isset($data['monthly_limit_usd']) || $data['monthly_limit_usd'] <= 0) {
                    return 'budget.monthly_limit_usd must be positive';
                }
                if (empty($data['currency'])) {
                    return 'budget.currency is required';
                }
                break;

            case 'invites':
                if (isset($data['emails']) && is_array($data['emails'])) {
                    foreach ($data['emails'] as $email) {
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            return 'invites.emails contains invalid email';
                        }
                    }
                }
                break;

            case 'strictness':
                if (empty($data['automation_level'])) {
                    return 'strictness.automation_level is required';
                }
                if (!in_array($data['automation_level'], ['manual', 'assisted', 'autonomous'], true)) {
                    return 'strictness.automation_level must be manual, assisted, or autonomous';
                }
                break;

            case 'branding':
                if (isset($data['primary_color']) && !preg_match('/^#[a-fA-F0-9]{6}$/', $data['primary_color'])) {
                    return 'branding.primary_color must be valid hex color';
                }
                break;
        }

        return null;
    }
}
