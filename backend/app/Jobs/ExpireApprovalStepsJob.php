<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ApprovalEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/*
| Sweep overdue approval-chain steps: escalate those with an
| escalate_to_role, expire the rest (which expires the whole approval
| and notifies the owning resource).
*/
class ExpireApprovalStepsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function handle(ApprovalEngine $engine): void
    {
        $acted = $engine->expireOverdueSteps();

        if ($acted > 0) {
            Log::info('Approval step sweep acted on overdue steps', ['count' => $acted]);
        }
    }
}
