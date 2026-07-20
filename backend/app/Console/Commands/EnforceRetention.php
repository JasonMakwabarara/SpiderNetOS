<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Enforces config/retention.php TTLs: purges high-volume raw usage rows and
 * scrubs old message bodies past their window; prunes expired DSAR export
 * artifacts. Aggregates and the event/audit chain are never touched.
 *
 *   php artisan spidernet:compliance:enforce-retention [--dry-run]
 */
class EnforceRetention extends Command
{
    protected $signature = 'spidernet:compliance:enforce-retention {--dry-run}';

    protected $description = 'Purge/scrub data past its retention window (config/retention.php).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info('Retention sweep'.($dry ? ' (dry-run)' : ''));

        // Raw per-call usage records (aggregates are retained separately).
        $usageCut = now()->subDays((int) config('retention.usage_records_days'));
        if (Schema::hasTable('usage_records') && Schema::hasColumn('usage_records', 'created_at')) {
            $q = DB::table('usage_records')->where('created_at', '<', $usageCut);
            $n = $q->count();
            $this->line("  usage_records older than {$usageCut->toDateString()}: {$n}");
            if (! $dry && $n) {
                $q->delete();
            }
        }

        // Old message bodies → tombstone (thread/metadata preserved).
        $msgCut = now()->subDays((int) config('retention.message_bodies_days'));
        if (Schema::hasTable('conversation_messages') && Schema::hasColumn('conversation_messages', 'created_at')) {
            $q = DB::table('conversation_messages')->where('created_at', '<', $msgCut)->where('body', '!=', '[expired]');
            $n = $q->count();
            $this->line("  message bodies older than {$msgCut->toDateString()}: {$n}");
            if (! $dry && $n) {
                $q->update(['body' => '[expired]']);
            }
        }

        // Expired DSAR export artifacts.
        $artCut = now()->subDays((int) config('retention.dsar_artifact_days'));
        if (Schema::hasTable('dsar_requests')) {
            $stale = DB::table('dsar_requests')
                ->whereNotNull('artifact_path')
                ->where('completed_at', '<', $artCut)
                ->get(['id', 'artifact_path']);
            $this->line("  expired DSAR artifacts: {$stale->count()}");
            if (! $dry) {
                foreach ($stale as $row) {
                    Storage::disk('local')->delete($row->artifact_path);
                    DB::table('dsar_requests')->where('id', $row->id)->update(['artifact_path' => null]);
                }
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
