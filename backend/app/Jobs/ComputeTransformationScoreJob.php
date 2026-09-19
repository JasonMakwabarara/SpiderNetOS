<?php

namespace App\Jobs;

use App\Services\TransformationScore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ComputeTransformationScoreJob
 *
 * B6 — scheduled job that computes TS for recent atlas_interactions rows
 * missing a final_ts or older than the re-scoring window.
 *
 * Batches are small and non-blocking. Scoring failures are tolerated per-row.
 */
class ComputeTransformationScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30, 120];

    public function __construct(
        private readonly int $batchSize = 200,
    ) {}

    public function handle(TransformationScore $scorer): void
    {
        // Scope: unscored rows first, then rows that have been scored but
        // had significant late-arriving behavior events (rescoring window: 24h).
        $rescoreWindow = now()->subHours(24);

        $rows = DB::table('atlas_interactions')
            ->where(function ($q) use ($rescoreWindow) {
                $q->whereNull('final_ts')
                    ->orWhere(function ($q2) use ($rescoreWindow) {
                        $q2->whereNotNull('final_ts')
                            ->where('scored_at', '<', $rescoreWindow)
                            ->whereColumn('updated_at', '>', 'scored_at');
                    });
            })
            ->orderBy('created_at')
            ->limit($this->batchSize)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $scored = 0;
        foreach ($rows as $row) {
            try {
                $decoded = [
                    'atlas_response' => $this->decode($row->atlas_response),
                    'execution_result' => $this->decode($row->execution_result),
                    'parsed_intent' => $this->decode($row->parsed_intent),
                    'clicked_expand' => (bool) $row->clicked_expand,
                    'accepted_action' => (bool) $row->accepted_action,
                    'follow_up' => (bool) $row->follow_up,
                    'time_on_response_ms' => $row->time_on_response_ms,
                    'rating' => $row->rating,
                ];

                $score = $scorer->compute($decoded);

                DB::table('atlas_interactions')
                    ->where('id', $row->id)
                    ->update(array_merge($score, [
                        'scored_at' => now(),
                        'updated_at' => now(),
                    ]));

                $scored++;
            } catch (\Throwable $e) {
                Log::warning('[ComputeTransformationScoreJob] failed row: '.$e->getMessage(), [
                    'interaction_id' => $row->id ?? null,
                ]);
            }
        }

        Log::info("[ComputeTransformationScoreJob] Scored {$scored} of {$rows->count()} rows.");
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
