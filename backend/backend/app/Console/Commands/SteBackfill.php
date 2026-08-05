<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\EventStore;
use App\Services\Projections\StateTransitionProjection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfills STE projection tables by replaying event_log.
 *
 * Three modes (plan §12.4):
 *   ste:backfill                              → dry-run, counts only
 *   ste:backfill --confirm                    → destructive: TRUNCATE + full replay
 *   ste:backfill --confirm --from=YYYY-MM-DD  → additive replay (no truncate)
 *
 * Wraps everything in DB::transaction so a mid-replay failure leaves the old
 * projection intact. Emits ste.backfill.{started,completed,failed} events into
 * event_log for audit trail.
 */
class SteBackfill extends Command
{
    protected $signature = 'ste:backfill
        {--confirm            : Execute the backfill (otherwise dry-run)}
        {--from=              : ISO date/datetime: replay events on/after this instant (additive)}
        {--to=                : ISO date/datetime: upper bound on occurred_at}
        {--tenant=            : Restrict to a single tenant UUID}
        {--chain=             : Restrict to a single chain (session_lifecycle | tenant_lifecycle)}
        {--chunk=500          : Events per transaction chunk}';

    protected $description = 'Replay event_log into STE projection tables (destructive by default; additive with --from)';

    public function handle(
        StateTransitionProjection $projection,
        EventStore $eventStore,
    ): int {
        $confirm  = (bool) $this->option('confirm');
        $from     = $this->option('from');
        $to       = $this->option('to');
        $tenantId = $this->option('tenant');
        $chain    = $this->option('chain');
        $chunkSz  = max(10, (int) $this->option('chunk'));

        $additive = $confirm && !empty($from);

        // --- 1. Count in scope -------------------------------------------------
        $q = Event::query()->orderBy('sequence_num');
        if ($from)     $q->where('occurred_at', '>=', $from);
        if ($to)       $q->where('occurred_at', '<=', $to);
        if ($tenantId) $q->where('tenant_id', $tenantId);

        $total = (clone $q)->count();
        $this->info(sprintf(
            'STE backfill scope: %d events | from=%s to=%s tenant=%s chain=%s',
            $total, $from ?: '-', $to ?: '-', $tenantId ?: 'all', $chain ?: 'all'
        ));

        if (!$confirm) {
            $this->line('DRY-RUN. Pass --confirm to execute.');
            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->warn('Nothing to replay.');
            return self::SUCCESS;
        }

        // --- 2. Emit started event (outside transaction so it survives failures)
        $this->emitAudit($eventStore, 'ste.backfill.started', [
            'mode'           => $additive ? 'additive' : 'destructive',
            'tenant_id'      => $tenantId,
            'chain'          => $chain,
            'from'           => $from,
            'to'             => $to,
            'expected_rows'  => $total,
        ]);

        $start   = microtime(true);
        $written = 0;
        $unmappedBefore = DB::table('ste_unmapped_events')->count();

        try {
            DB::transaction(function () use ($q, $projection, &$written, $chunkSz, $additive, $tenantId, $chain) {
                // Destructive mode: wipe projections first
                if (!$additive) {
                    $this->truncateProjections($tenantId, $chain);
                }

                $q->chunkById($chunkSz, function ($events) use ($projection, &$written) {
                    foreach ($events as $event) {
                        $projection->handle($event);
                        $written++;
                    }
                }, 'sequence_num', 'sequence_num');
            });

            $duration = (int) round((microtime(true) - $start) * 1000);
            $unmappedAfter = DB::table('ste_unmapped_events')->count();

            $this->emitAudit($eventStore, 'ste.backfill.completed', [
                'rows_written'         => $written,
                'duration_ms'          => $duration,
                'unmapped_types_delta' => max(0, $unmappedAfter - $unmappedBefore),
            ]);

            $this->info("Backfill complete: {$written} events in {$duration}ms.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->emitAudit($eventStore, 'ste.backfill.failed', [
                'rows_written_before_failure' => $written,
                'exception_class'             => get_class($e),
                'message'                     => $e->getMessage(),
            ]);

            $this->error('Backfill failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function truncateProjections(?string $tenantId, ?string $chain): void
    {
        if ($tenantId) {
            DB::table('ste_transitions')
                ->where('tenant_id', $tenantId)
                ->when($chain, fn ($q) => $q->where('chain', $chain))
                ->delete();
            DB::table('ste_session_states')->where('tenant_id', $tenantId)->delete();
            DB::table('ste_tenant_states')->where('tenant_id', $tenantId)->delete();
            return;
        }

        if ($chain) {
            DB::table('ste_transitions')->where('chain', $chain)->delete();
            // state tables aren't scoped per chain; leave them for full-scope truncate
            return;
        }

        // Full truncate
        DB::statement('TRUNCATE ste_transitions, ste_session_states, ste_tenant_states, ste_unmapped_events RESTART IDENTITY');
    }

    private function emitAudit(EventStore $eventStore, string $eventType, array $payload): void
    {
        try {
            $eventStore->append(
                tenantId:      '00000000-0000-0000-0000-000000000000',
                aggregateType: 'ste_backfill',
                aggregateId:   (string) Str::uuid(),
                eventType:     $eventType,
                payload:       $payload,
                metadata:      ['command' => 'ste:backfill'],
            );
        } catch (\Throwable $e) {
            // Audit failure should not break the command
            $this->warn("audit emit failed: {$e->getMessage()}");
        }
    }
}
