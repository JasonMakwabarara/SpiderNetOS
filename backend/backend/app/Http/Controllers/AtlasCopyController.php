<?php

namespace App\Http\Controllers;

use App\Services\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AtlasCopyController — Atlas Copy Delivery API (§11.4)
 *
 * GET  /api/atlas/copy
 *   Serves a transformation-scored copy variant for the requested surface.
 *   p95 latency budget: < 120 ms (within the 1.5 s Atlas loop).
 *
 * POST /api/atlas/copy/{variantId}/event
 *   Records a user interaction (click | action | conversion) and
 *   enqueues a reward-computation update within the 200 ms soft-RT budget.
 */
class AtlasCopyController extends Controller
{
    // -----------------------------------------------------------------------
    // GET /api/atlas/copy
    // -----------------------------------------------------------------------

    public function serve(Request $request): JsonResponse
    {
        $surface = $request->query('surface', 'empty_state');
        $rawCtx  = $request->query('context', '');

        $context = [];
        if ($rawCtx) {
            $decoded = base64_decode($rawCtx, strict: true);
            if ($decoded !== false) {
                $context = json_decode($decoded, true) ?? [];
            }
        }

        // Check surface-level kill switch
        if (FeatureFlag::fallback('atlas.copy.' . $surface)) {
            return $this->fallbackResponse($surface);
        }

        // Select a variant using Thompson Sampling bandit (§11.11)
        $variant = $this->selectVariant($surface, $context, $request);

        if (!$variant) {
            return $this->fallbackResponse($surface);
        }

        // Log impression
        $this->logImpression($variant, $surface, $context, $request);

        return response()->json([
            'variant_id'   => $variant->id,
            'surface'      => $surface,
            'text'         => $variant->features['text'] ?? '',
            'cta'          => $variant->features['cta'] ?? null,
            'trust_score'  => (float) $variant->trust_score,
            'predicted_ts' => (float) $variant->predicted_ts,
            'fallback'     => false,
            'expires_at'   => now()->addMinutes(60)->toIso8601String(),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /api/atlas/copy/{variantId}/event
    // -----------------------------------------------------------------------

    public function recordEvent(Request $request, string $variantId): JsonResponse
    {
        $validated = $request->validate([
            'kind'     => 'required|in:click,action,conversion',
            'dwell_ms' => 'nullable|integer|min:0',
        ]);

        $kind    = $validated['kind'];
        $dwellMs = $validated['dwell_ms'] ?? null;
        $now     = now();

        // Update the impression row (most recent for this variant for this user)
        $tenantId = $request->attributes->get('tenant_id');
        $userId   = $request->user()?->id;

        $impression = DB::table('atlas_copy_impressions')
            ->where('variant_id', $variantId)
            ->where('tenant_id', $tenantId)
            ->whereNull('clicked_at')  // First un-closed impression
            ->orderByDesc('shown_at')
            ->first();

        if ($impression) {
            $updateData = ['dwell_ms' => $dwellMs ?? $impression->dwell_ms];

            match ($kind) {
                'click'      => $updateData['clicked_at']    = $now,
                'action'     => $updateData['action_at']      = $now,
                'conversion' => $updateData['conversion_at']  = $now,
            };

            DB::table('atlas_copy_impressions')
                ->where('id', $impression->id)
                ->update($updateData);
        }

        // Increment bandit counters and compute blended reward (< 200 ms soft-RT)
        $this->updateBanditCounters($variantId, $kind, $dwellMs);

        return response()->json(['recorded' => true]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Thompson Sampling variant selection.
     *
     * Resolution:
     *   1. Fetch active variants for the surface.
     *   2. Apply impression-floor enforcement.
     *   3. Sample θ ~ Beta(alpha, beta) with posterior temperature scaling.
     *   4. Apply trust_score and predicted_ts gates.
     *   5. Return top-ranked variant, or null if none pass gates.
     */
    private function selectVariant(string $surface, array $context, Request $request): ?object
    {
        $algo        = FeatureFlag::value('atlas.bandit.algo', $request->attributes->get('tenant_id'));
        $temperature = (float) FeatureFlag::value('atlas.bandit.temperature');
        $temperature = max(0.5, min(1.2, $temperature));
        $floor       = (int) FeatureFlag::value('atlas.bandit.min_impressions_floor');

        $variants = DB::table('atlas_copy_variants')
            ->where('surface', $surface)
            ->where('status', 'active')
            ->get();

        if ($variants->isEmpty()) {
            return null;
        }

        $scored = [];

        foreach ($variants as $variant) {
            // Trust and TS gates (§4.3.2)
            if ((float) $variant->trust_score < 0.80 || (float) $variant->predicted_ts < 0.72) {
                continue;
            }

            $alpha = (float) $variant->alpha + $this->cohortPriorAlpha($surface);
            $beta  = (float) $variant->beta  + $this->cohortPriorBeta($surface);

            if ($algo === 'thompson') {
                $theta = $this->betaSample($alpha, $beta, $temperature);
            } else {
                // epsilon-greedy fallback
                $epsilon = max(0.05, 1.0 / max(1, sqrt((float) $variant->impressions)));
                $theta   = (random_int(0, 100) / 100) < $epsilon
                    ? (random_int(0, 100) / 100)
                    : (float) $variant->avg_reward;
            }

            // Impression floor enforcement (§11.11)
            if ((int) $variant->impressions < $floor) {
                $theta = max($theta, mt_rand(50, 100) / 100);
            }

            $scored[] = ['variant' => $variant, 'theta' => $theta];
        }

        if (empty($scored)) {
            return null;
        }

        usort($scored, fn($a, $b) => $b['theta'] <=> $a['theta']);
        return $scored[0]['variant'];
    }

    /**
     * Approximate Beta(α,β) sample using Johnk's method (no special function library needed).
     * Temperature scales the parameters to widen (T>1) or narrow (T<1) the distribution.
     */
    private function betaSample(float $alpha, float $beta, float $temperature): float
    {
        // Scale parameters by temperature
        $a = $alpha / $temperature;
        $b = $beta  / $temperature;
        $a = max(0.01, $a);
        $b = max(0.01, $b);

        // Johnk's method
        while (true) {
            $u = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;
            $v = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;
            $x = pow($u, 1.0 / $a);
            $y = pow($v, 1.0 / $b);
            if ($x + $y <= 1.0) {
                return $x / ($x + $y);
            }
        }
    }

    /** Cohort-level smoothing prior — returns historical CTR-based alpha offset. */
    private function cohortPriorAlpha(string $surface): float
    {
        // Simplified: global click rate across the surface
        $clicks      = (float) DB::table('atlas_copy_variants')->where('surface', $surface)->sum('clicks');
        $impressions = (float) DB::table('atlas_copy_variants')->where('surface', $surface)->sum('impressions');
        return $impressions > 0 ? ($clicks / $impressions) * 10 : 1.0;
    }

    private function cohortPriorBeta(string $surface): float
    {
        $clicks      = (float) DB::table('atlas_copy_variants')->where('surface', $surface)->sum('clicks');
        $impressions = (float) DB::table('atlas_copy_variants')->where('surface', $surface)->sum('impressions');
        $noClicks    = max(0, $impressions - $clicks);
        return $impressions > 0 ? ($noClicks / $impressions) * 10 : 1.0;
    }

    private function logImpression(object $variant, string $surface, array $context, Request $request): void
    {
        try {
            DB::table('atlas_copy_impressions')->insert([
                'variant_id' => $variant->id,
                'tenant_id'  => $request->attributes->get('tenant_id'),
                'user_id'    => $request->user()?->id,
                'surface'    => $surface,
                'context'    => json_encode($context),
                'shown_at'   => now(),
            ]);

            DB::table('atlas_copy_variants')
                ->where('id', $variant->id)
                ->increment('impressions');
        } catch (\Throwable $e) {
            Log::warning('[AtlasCopy] impression log failed: ' . $e->getMessage());
        }
    }

    /**
     * Update bandit counters and compute blended reward within ~200 ms.
     * Reward = 0.6 * predicted_ts + 0.4 * observed_signal
     */
    private function updateBanditCounters(string $variantId, string $kind, ?int $dwellMs): void
    {
        try {
            $variant = DB::table('atlas_copy_variants')->find($variantId);
            if (!$variant) {
                return;
            }

            $increment = match ($kind) {
                'click'      => ['clicks'      => 1],
                'action'     => ['actions'     => 1],
                'conversion' => ['conversions' => 1],
            };

            DB::table('atlas_copy_variants')->where('id', $variantId)->increment(
                key(   $increment),
                current($increment),
            );

            // Observed reward signal (surface-specific primary metric)
            $observedReward = match ($kind) {
                'click'      => 0.4,
                'action'     => 0.7,
                'conversion' => 1.0,
            };

            // Blended reward (§4.3.9)
            $blendedReward = 0.6 * (float) $variant->predicted_ts + 0.4 * $observedReward;

            // Update rolling average reward
            $newImpressions = (int) $variant->impressions;
            $oldSum         = (float) $variant->sum_reward;
            $newSum         = $oldSum + $blendedReward;
            $newAvg         = $newImpressions > 0 ? $newSum / $newImpressions : $blendedReward;

            // Update Thompson parameters (alpha/beta for success/failure)
            $alphaIncr = in_array($kind, ['action', 'conversion']) ? $blendedReward : 0;
            $betaIncr  = $alphaIncr === 0 ? (1 - $blendedReward) : 0;

            DB::table('atlas_copy_variants')->where('id', $variantId)->update([
                'sum_reward' => $newSum,
                'avg_reward' => $newAvg,
                'alpha'      => DB::raw("alpha + {$alphaIncr}"),
                'beta'       => DB::raw("beta  + {$betaIncr}"),
                'updated_at' => now(),
            ]);

            // Write reward back to impression row
            DB::table('atlas_copy_impressions')
                ->where('variant_id', $variantId)
                ->whereNull('reward')
                ->orderByDesc('shown_at')
                ->limit(1)
                ->update(['reward' => $blendedReward]);

        } catch (\Throwable $e) {
            Log::warning('[AtlasCopy] bandit update failed: ' . $e->getMessage());
        }
    }

    private function fallbackResponse(string $surface): JsonResponse
    {
        // Returns a static fallback; variant_id is null so cockpit skips event reporting
        return response()->json([
            'variant_id'   => null,
            'surface'      => $surface,
            'text'         => $this->fallbackText($surface),
            'cta'          => null,
            'trust_score'  => 1.0,
            'predicted_ts' => 1.0,
            'fallback'     => true,
            'expires_at'   => now()->addMinutes(5)->toIso8601String(),
        ]);
    }

    private function fallbackText(string $surface): string
    {
        return match ($surface) {
            'empty_state'   => 'Your usage insights will appear here once your first request is processed.',
            'banner'        => 'Your usage insights are live — see where your spend is going today.',
            'modal'         => 'Your usage data is now accurate and up to date.',
            'tooltip'       => 'Total API requests processed on this day.',
            'success_state' => 'Your usage insights are live and accurate.',
            'error_state'   => 'Usage data is temporarily unavailable. We\'re working on it.',
            default         => '',
        };
    }
}
