<?php

declare(strict_types=1);

namespace Syriable\Ledger\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Syriable\Ledger\Enums\EntryDirection;
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

        // Clear all balances belonging to this ledger's accounts.
        DB::table($balancesTable)
            ->whereIn('account_id', Account::query()->where('ledger_id', $ledger->id)->select('id'))
            ->delete();

        // Recompute from entries.
        Account::query()
            ->where('ledger_id', $ledger->id)
            ->chunkById(500, function ($accounts) use ($balancesTable, $now): void {
                $rows = [];

                foreach ($accounts as $account) {
                    $debits = (int) Entry::query()
                        ->where('account_id', $account->id)
                        ->where('direction', EntryDirection::Debit->value)
                        ->sum('amount');

                    $credits = (int) Entry::query()
                        ->where('account_id', $account->id)
                        ->where('direction', EntryDirection::Credit->value)
                        ->sum('amount');

                    $sign = $account->signMultiplier(EntryDirection::Debit);

                    $rows[] = [
                        'account_id' => $account->id,
                        'currency' => $account->currency,
                        'debit_total' => $debits,
                        'credit_total' => $credits,
                        'balance' => ($sign * $debits) + (-$sign * $credits),
                        'version' => 1,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table($balancesTable)->insert($rows);
                }
            });
    }
}
