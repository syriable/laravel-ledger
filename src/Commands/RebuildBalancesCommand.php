<?php

declare(strict_types=1);

namespace Syriable\Ledger\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Balance;
use Syriable\Ledger\Models\Entry;
use Syriable\Ledger\Models\Ledger as LedgerModel;
use Syriable\Ledger\Recording\Clock;

/**
 * php artisan ledger:rebuild-balances [--ledger=slug]
 *
 * Truncates the balances projection (optionally scoped to one ledger) and
 * rebuilds it from the entries table. Since entries are the source of truth,
 * this is always safe — it produces the same projection the recorder would
 * have produced incrementally.
 *
 * Implementation: one DELETE + one INSERT...SELECT per ledger, regardless
 * of how many accounts or entries the ledger contains. Safe to run on
 * large ledgers without OOM or N+1 amplification.
 *
 * Wrapped in a DB transaction so a failure mid-rebuild leaves the projection
 * unchanged rather than half-built.
 */
final class RebuildBalancesCommand extends Command
{
    protected $signature = 'ledger:rebuild-balances {--ledger= : Restrict to a single ledger slug}';

    protected $description = 'Rebuild the balances projection from entries';

    public function handle(Clock $clock): int
    {
        $ledgersQuery = LedgerModel::query();
        if (is_string($slug = $this->option('ledger')) && $slug !== '') {
            $ledgersQuery->where('slug', $slug);
        }

        $ledgers = $ledgersQuery->get();

        if ($ledgers->isEmpty()) {
            $this->components->warn('No ledgers found.');

            return self::SUCCESS;
        }

        $now = $clock->now();

        Balance::openRecorderWindow();
        try {
            DB::transaction(function () use ($ledgers, $now): void {
                foreach ($ledgers as $ledger) {
                    $this->rebuildForLedger($ledger, $now);
                }
            });
        } finally {
            Balance::closeRecorderWindow();
        }

        $this->components->info('Balances rebuilt.');

        return self::SUCCESS;
    }

    private function rebuildForLedger(LedgerModel $ledger, \DateTimeInterface $now): void
    {
        $this->components->info("Rebuilding balances for ledger: {$ledger->slug}");

        $balancesTable = (new Balance)->getTable();
        $accountsTable = (new Account)->getTable();
        $entriesTable = (new Entry)->getTable();

        // 1. Clear all balances belonging to this ledger's accounts.
        DB::table($balancesTable)
            ->whereIn('account_id', Account::query()->where('ledger_id', $ledger->id)->select('id'))
            ->delete();

        // 2. Rebuild every account's row in a single INSERT ... SELECT.
        //    The signed `balance` column is computed in SQL via CASE on
        //    a.normal_balance, so the round-trip count is O(1) per ledger.
        $debitsExpr = "COALESCE(SUM(CASE WHEN e.direction = 'debit'  THEN e.amount ELSE 0 END), 0)";
        $creditsExpr = "COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount ELSE 0 END), 0)";
        $balanceExpr = "CASE WHEN a.normal_balance = 'debit' THEN ({$debitsExpr}) - ({$creditsExpr}) ELSE ({$creditsExpr}) - ({$debitsExpr}) END";

        $select = DB::table("{$accountsTable} as a")
            ->leftJoin("{$entriesTable} as e", 'e.account_id', '=', 'a.id')
            ->where('a.ledger_id', $ledger->id)
            ->groupBy('a.id', 'a.currency', 'a.normal_balance')
            ->select('a.id as account_id')
            ->selectRaw('a.currency as currency')
            ->selectRaw("{$debitsExpr} as debit_total")
            ->selectRaw("{$creditsExpr} as credit_total")
            ->selectRaw("{$balanceExpr} as balance")
            ->selectRaw('1 as version')
            ->selectRaw('? as updated_at', [$now->format('Y-m-d H:i:s.u')]);

        DB::table($balancesTable)->insertUsing(
            ['account_id', 'currency', 'debit_total', 'credit_total', 'balance', 'version', 'updated_at'],
            $select,
        );
    }
}
