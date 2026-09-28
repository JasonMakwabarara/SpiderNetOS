<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ApprovalActions;
use Illuminate\Console\Command;

/**
 * Drain approval actions a decision committed and nothing completed.
 *
 * Runs what is pending (a process that died after the decision's commit, or a
 * transactional hook that failed and may now succeed), and marks external
 * hooks that claimed a record and never reported back as uncertain. Exits
 * non-zero while any record needs a person — failed or uncertain — so a
 * scheduled run surfaces it instead of logging it quietly.
 */
class RecoverApprovalActions extends Command
{
    protected $signature = 'approvals:recover-actions
        {--older-than=60 : Leave pending records younger than this many seconds to the decision that wrote them}
        {--lease= : Seconds after which a claimed external record is uncertain (default: config approvals.action_lease_seconds)}';

    protected $description = 'Run approval actions that were decided but never completed';

    public function handle(ApprovalActions $actions): int
    {
        $lease = $this->option('lease');
        $counts = $actions->recover((int) $this->option('older-than'), $lease === null ? null : (int) $lease);
        $outstanding = $actions->outstanding();

        $this->line(sprintf(
            'completed %d · still pending %d · failed %d · uncertain %d · lapsed claims %d · needing a person %d',
            $counts['completed'], $counts['pending'], $counts['failed'], $counts['uncertain'], $counts['lapsed'], $outstanding,
        ));

        return $outstanding === 0 ? self::SUCCESS : self::FAILURE;
    }
}
