<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FinancialAccount;
use App\Services\Financial\LedgerService;
use Illuminate\Console\Command;

/*
| Historical financial_accounts.balance values were computed with
| sign handling that ignored account type (liability/equity/revenue
| balances drifted in the wrong direction). Run once per tenant after
| the LedgerService normal-balance fix.
*/
class RebuildLedgerBalances extends Command
{
    protected $signature = 'ledger:rebuild-balances {--tenant= : Limit to one tenant id} {--dry-run : Report drift without writing}';

    protected $description = 'Recompute financial account balances from ledger entries using normal-balance semantics';

    public function handle(LedgerService $ledger): int
    {
        $query = FinancialAccount::query();
        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', $tenant);
        }

        $fixed = 0;
        $query->chunkById(100, function ($accounts) use ($ledger, &$fixed) {
            foreach ($accounts as $account) {
                $correct = $ledger->recomputeBalance($account);

                if (bccomp($correct, (string) $account->balance, 4) === 0) {
                    continue;
                }

                $this->line(sprintf(
                    '%s [%s/%s]: %s -> %s',
                    $account->id,
                    $account->tenant_id,
                    $account->type,
                    $account->balance,
                    $correct,
                ));

                if (! $this->option('dry-run')) {
                    $account->update(['balance' => $correct]);
                }
                $fixed++;
            }
        });

        $this->info(sprintf(
            '%d account(s) %s.',
            $fixed,
            $this->option('dry-run') ? 'with drift (dry run, unchanged)' : 'rebuilt',
        ));

        return self::SUCCESS;
    }
}
