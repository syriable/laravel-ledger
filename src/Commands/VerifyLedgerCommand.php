<?php

declare(strict_types=1);

namespace Syriable\Ledger\Commands;

use Illuminate\Console\Command;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Balance;
use Syriable\Ledger\Models\Entry;
use Syriable\Ledger\Models\Ledger as LedgerModel;
use Syriable\Ledger\Models\Transaction;

/**
 * php artisan ledger:verify [--ledger=slug]
 *
 * Asserts the package's invariants over the data on disk:
 *
 *   1. Every transaction is balanced (Σ debits == Σ credits).
 *   2. Every ledger has zero-sum entries.
 *   3. Every balance projection equals SUM(signed entries) for that account.
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

    private function verifyTransactionsBalanced(LedgerModel $ledger): int
    {
        $failures = 0;

        Transaction::query()
            ->where('ledger_id', $ledger->id)
            ->select('id')
            ->chunkById(500, function ($chunk) use (&$failures): void {
                foreach ($chunk as $tx) {
                    $debits = (int) Entry::query()
                        ->where('transaction_id', $tx->id)
                        ->where('direction', EntryDirection::Debit->value)
                        ->sum('amount');

                    $credits = (int) Entry::query()
                        ->where('transaction_id', $tx->id)
                        ->where('direction', EntryDirection::Credit->value)
                        ->sum('amount');

                    if ($debits !== $credits) {
                        $this->components->error(
                            "Transaction {$tx->id} is imbalanced: debits={$debits} credits={$credits}"
                        );
                        $failures++;
                    }
                }
            });

        return $failures;
    }

    private function verifyLedgerZeroSum(LedgerModel $ledger): int
    {
        $debits = (int) Entry::query()
            ->where('ledger_id', $ledger->id)
            ->where('direction', EntryDirection::Debit->value)
            ->sum('amount');

        $credits = (int) Entry::query()
            ->where('ledger_id', $ledger->id)
            ->where('direction', EntryDirection::Credit->value)
            ->sum('amount');

        if ($debits !== $credits) {
            $this->components->error(
                "Ledger {$ledger->slug} is not zero-sum: debits={$debits} credits={$credits}"
            );

            return 1;
        }

        return 0;
    }

    private function verifyBalancesMatchEntries(LedgerModel $ledger): int
    {
        $failures = 0;

        Account::query()
            ->where('ledger_id', $ledger->id)
            ->chunkById(500, function ($accounts) use (&$failures): void {
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
                    $expected = ($sign * $debits) + (-$sign * $credits);

                    /** @var Balance|null $projection */
                    $projection = Balance::query()->where('account_id', $account->id)->first();
                    $projected = $projection !== null ? $projection->balance : 0;

                    if ($projected !== $expected) {
                        $this->components->error(
                            "Account {$account->code} ({$account->id}) projection drift: ".
                            "projected={$projected} expected={$expected}"
                        );
                        $failures++;
                    }
                }
            });

        return $failures;
    }
}
