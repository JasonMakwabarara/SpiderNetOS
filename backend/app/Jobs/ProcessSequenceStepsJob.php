<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Models\SequenceEnrollment;
use App\Services\Messaging\MessageDispatchService;
use App\Services\Sales\LeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * DAG nodes have no time-based wait primitive (DagExecutionService executes
 * action/trigger/notify/webhook/log nodes synchronously — see the `delay:`
 * attributes in flow YAMLs, which are currently ignored). Multi-day nurture
 * sequences run on this dedicated scheduler instead, driven by
 * packages/feature-packs/sales-crm/flows/nurture-sequence.yaml.
 */
class ProcessSequenceStepsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PACK_ID = 'sales-crm';

    public function handle(MessageDispatchService $dispatch, LeadService $leadService): void
    {
        $sequenceDef = $this->loadSequenceDefinition();
        $steps = $sequenceDef['steps'] ?? [];
        if (empty($steps)) {
            return;
        }

        if ($this->inQuietHours($sequenceDef['quiet_hours'] ?? null)) {
            return;
        }

        $enrollments = SequenceEnrollment::due()->limit(200)->get();

        foreach ($enrollments as $enrollment) {
            try {
                $this->processOne($enrollment, $steps, $dispatch, $leadService);
            } catch (\Throwable $e) {
                Log::error('ProcessSequenceStepsJob: enrollment failed', [
                    'enrollment_id' => $enrollment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function processOne(SequenceEnrollment $enrollment, array $steps, MessageDispatchService $dispatch, LeadService $leadService): void
    {
        $nextStepIndex = $enrollment->current_step; // 0-based index into $steps
        if (! isset($steps[$nextStepIndex])) {
            $enrollment->update(['status' => 'completed']);

            return;
        }

        $step = $steps[$nextStepIndex];
        $lead = Lead::forTenant($enrollment->tenant_id)->find($enrollment->lead_id);

        if (! $lead || in_array($lead->stage, ['won', 'lost'], true)) {
            $enrollment->update(['status' => 'cancelled']);

            return;
        }

        $result = $dispatch->sendTemplate($lead, $step['channel'], $step['template_key']);
        if (! $result['success']) {
            Log::warning('ProcessSequenceStepsJob: send failed, will retry next tick', [
                'lead_id' => $lead->id, 'error' => $result['error'] ?? null,
            ]);

            // Push next_run_at forward briefly rather than hot-looping retries.
            $enrollment->update(['next_run_at' => now()->addMinutes(15)]);

            return;
        }

        $isLastStep = ! isset($steps[$nextStepIndex + 1]);

        if ($isLastStep) {
            $enrollment->update(['status' => 'completed', 'current_step' => $nextStepIndex + 1]);

            if (($step['on_complete'] ?? null) === 'mark_recycled' && $lead->stage !== 'recycled') {
                $leadService->transitionStage($lead, 'recycled', ['reason' => 'sequence_completed_no_reply']);
            }

            return;
        }

        $nextStep = $steps[$nextStepIndex + 1];
        $enrollment->update([
            'current_step' => $nextStepIndex + 1,
            'next_run_at' => now()->addDays((int) ($nextStep['wait_days'] ?? 1)),
        ]);
    }

    private function inQuietHours(?array $quietHours): bool
    {
        if (! $quietHours || empty($quietHours['start']) || empty($quietHours['end'])) {
            return false;
        }

        $now = now()->format('H:i');
        $start = $quietHours['start'];
        $end = $quietHours['end'];

        // Overnight window, e.g. 20:00 -> 08:00
        if ($start > $end) {
            return $now >= $start || $now < $end;
        }

        return $now >= $start && $now < $end;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSequenceDefinition(): array
    {
        $path = storage_path('app/feature-packs/'.self::PACK_ID.'/flows/nurture-sequence.yaml');
        if (! is_readable($path)) {
            $path = dirname(base_path()).'/packages/feature-packs/'.self::PACK_ID.'/flows/nurture-sequence.yaml';
        }
        if (! is_readable($path)) {
            return [];
        }

        return Yaml::parseFile($path);
    }
}
