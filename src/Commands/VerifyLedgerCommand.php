<?php

declare(strict_types=1);

namespace Syriable\Ledger\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Balance;
use Syriable\Ledger\Models\Entry;
use Syriable\Ledger\Models\Ledger as LedgerModel;

/**
 * php artisan ledger:verify [--ledger=slug]
 *
 * Asserts the package's invariants over the data on disk:
 *
 *   1. Every transaction is balanced (Σ debits == Σ credits).
 *   2. Every ledger has zero-sum entries.
 *   3. Every balance projection equals SUM(signed entries) for that account.
 *
 * All three checks run as set-based SQL aggregations — one query per
 * check, regardless of how many accounts or transactions the ledger
 * contains. The command is therefore safe to run against very large
 * ledgers on a schedule.
 *
 * Exits with code 1 on any drift so it can be wired into CI and daily crons.
 */
final class VerifyLedgerCommand extends Command
{
    protected $signature = 'ledger:verify {--ledger= : Restrict to a single ledger slug}';

    protected $description = 'Verify the integrity of one or all ledgers';

    public function handle(): int
    {
        $ledgersQuery = LedgerModel::query();
        if (is_string($slug = $this->option('ledger')) && $slug !== '') {
            $ledgersQuery->where('slug', $slug);
        }

        $ledgers = $ledgersQuery->get();

        if ($ledgers->isEmpty()) {
            $this->components->warn('No ledgers found to verify.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($ledgers as $ledger) {
            $this->components->info("Verifying ledger: {$ledger->slug}");

            $failures += $this->verifyTransactionsBalanced($ledger);
            $failures += $this->verifyLedgerZeroSum($ledger);
            $failures += $this->verifyBalancesMatchEntries($ledger);
        }

        if ($failures > 0) {
            $this->components->error("Verification failed with {$failures} invariant violation(s).");

            return self::FAILURE;
        }

        $this->components->info('All ledger invariants hold.');

        return self::SUCCESS;
    }

    /**
     * Set-based: one GROUP BY query returns every imbalanced transaction.
     */
    private function verifyTransactionsBalanced(LedgerModel $ledger): int
    {
        $entriesTable = (new Entry)->getTable();

        $debitsExpr = "COALESCE(SUM(CASE WHEN direction = 'debit'  THEN amount ELSE 0 END), 0)";
        $creditsExpr = "COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0)";

        $rows = DB::table($entriesTable)
            ->select('transaction_id')
            ->selectRaw("{$debitsExpr} AS debits")
            ->selectRaw("{$creditsExpr} AS credits")
            ->where('ledger_id', $ledger->id)
            ->groupBy('transaction_id')
            ->havingRaw("{$debitsExpr} <> {$creditsExpr}")
            ->get();

        foreach ($rows as $row) {
            $this->components->error(
                "Transaction {$row->transaction_id} is imbalanced: debits={$row->debits} credits={$row->credits}"
            );
        }

        return $rows->count();
    }

    /**
     * Single-row aggregation across the whole ledger.
     */
    private function verifyLedgerZeroSum(LedgerModel $ledger): int
    {
        $entriesTable = (new Entry)->getTable();

        $row = DB::table($entriesTable)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit'  THEN amount ELSE 0 END), 0) AS debits")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS credits")
            ->where('ledger_id', $ledger->id)
            ->first();

        $debits = (int) ($row->debits ?? 0);
        $credits = (int) ($row->credits ?? 0);

        if ($debits !== $credits) {
            $this->components->error(
                "Ledger {$ledger->slug} is not zero-sum: debits={$debits} credits={$credits}"
            );

            return 1;
        }

        return 0;
    }

    /**
     * Set-based: a single LEFT JOIN aggregation reports every account whose
     * projection disagrees with the sum-of-entries computed expectation.
     */
    private function verifyBalancesMatchEntries(LedgerModel $ledger): int
    {
        $accountsTable = (new Account)->getTable();
        $entriesTable = (new Entry)->getTable();
        $balancesTable = (new Balance)->getTable();

        $debitsExpr = "COALESCE(SUM(CASE WHEN e.direction = 'debit'  THEN e.amount ELSE 0 END), 0)";
        $creditsExpr = "COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount ELSE 0 END), 0)";
        $expectedExpr = "CASE WHEN a.normal_balance = 'debit' THEN ({$debitsExpr}) - ({$creditsExpr}) ELSE ({$creditsExpr}) - ({$debitsExpr}) END";
        $projectedExpr = 'COALESCE(b.balance, 0)';

        $rows = DB::table("{$accountsTable} as a")
            ->leftJoin("{$entriesTable} as e", 'e.account_id', '=', 'a.id')
            ->leftJoin("{$balancesTable} as b", 'b.account_id', '=', 'a.id')
            ->where('a.ledger_id', $ledger->id)
            ->groupBy('a.id', 'a.code', 'a.normal_balance', 'b.balance')
            ->select('a.id', 'a.code')
            ->selectRaw("{$projectedExpr} AS projected")
            ->selectRaw("{$expectedExpr} AS expected")
            ->havingRaw("{$projectedExpr} <> {$expectedExpr}")
            ->get();

        foreach ($rows as $row) {
            $this->components->error(
                "Account {$row->code} ({$row->id}) projection drift: ".
                "projected={$row->projected} expected={$row->expected}"
            );
        }

        return $rows->count();
    }
}
