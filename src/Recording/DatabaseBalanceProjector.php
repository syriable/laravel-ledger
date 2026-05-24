<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Balance;
use Syriable\Ledger\Models\Entry;

/**
 * Synchronously updates the balances projection inside the recorder's
 * own DB transaction.
 *
 * Why this is safe: the recorder already holds `SELECT ... FOR UPDATE`
 * locks on every affected account row, so concurrent postings touching
 * the same accounts will serialise behind those locks before reaching
 * this code path. There is no read-modify-write race.
 */
final class DatabaseBalanceProjector implements BalanceProjector
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<Entry>  $entries
     * @param  Collection<string, Account>  $accounts
     */
    public function apply(array $entries, Collection $accounts): void
    {
        // Aggregate per-account deltas: [accountId => ['debit'=>, 'credit'=>]]
        /** @var array<string, array{debit:int, credit:int}> $deltas */
        $deltas = [];
        foreach ($entries as $entry) {
            $id = $entry->account_id;
            $deltas[$id] ??= ['debit' => 0, 'credit' => 0];

            if ($entry->direction === EntryDirection::Debit) {
                $deltas[$id]['debit'] += $entry->amount;
            } else {
                $deltas[$id]['credit'] += $entry->amount;
            }
        }

        Account::openRecorderWindow();
        Balance::openRecorderWindow();
        try {
            foreach ($deltas as $accountId => $delta) {
                $account = $accounts->get($accountId);
                if ($account === null) {
                    // Validators run before us, so this is unreachable.
                    continue;
                }

                $sign = $account->signMultiplier(EntryDirection::Debit);
                $signedDelta = ($sign * $delta['debit']) + (-$sign * $delta['credit']);

                $this->upsertBalance($accountId, $account->currency, $delta['debit'], $delta['credit'], $signedDelta);
            }
        } finally {
            Balance::closeRecorderWindow();
            Account::closeRecorderWindow();
        }
    }

    private function upsertBalance(
        string $accountId,
        string $currency,
        int $debitDelta,
        int $creditDelta,
        int $signedDelta,
    ): void {
        $table = (new Balance)->getTable();
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        // Increment the existing projection row via fully bound parameters.
        // We could have used Laravel's upsert() with raw Expressions, but
        // that requires interpolating the deltas into SQL fragments. The
        // recorder already holds SELECT … FOR UPDATE on the account, so
        // concurrent postings on the same account serialise behind that
        // lock — splitting into UPDATE-then-INSERT here is race-safe.
        $affected = DB::update(
            "UPDATE {$table} SET ".
            'debit_total = debit_total + ?, '.
            'credit_total = credit_total + ?, '.
            'balance = balance + ?, '.
            'version = version + 1, '.
            'updated_at = ? '.
            'WHERE account_id = ?',
            [$debitDelta, $creditDelta, $signedDelta, $now, $accountId],
        );

        if ($affected > 0) {
            return;
        }

        // No existing row — first posting against this account. Insert the
        // initial projection. The FOR UPDATE on the account row makes this
        // safe against concurrent inserts.
        DB::table($table)->insert([
            'account_id' => $accountId,
            'currency' => $currency,
            'debit_total' => $debitDelta,
            'credit_total' => $creditDelta,
            'balance' => $signedDelta,
            'version' => 1,
            'updated_at' => $now,
        ]);
    }
}
