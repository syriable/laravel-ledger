<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Illuminate\Support\Collection;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Entry;

/**
 * BalanceProjector — applies committed entries to the projection table.
 *
 * The default DatabaseBalanceProjector runs inside the recorder's DB
 * transaction, keeping balances synchronously consistent with entries.
 * Alternative implementations (async-via-queue, Redis, computed-on-read)
 * can be bound in a consumer's service provider.
 *
 * Whatever the strategy, `ledger:verify` (and the property suite) must be
 * able to confirm that balances == SUM(signed entries) for every account.
 */
interface BalanceProjector
{
    /**
     * @param  list<Entry>  $entries  Entries already persisted in this transaction.
     * @param  Collection<string, Account>  $accounts  Locked accounts, keyed by id.
     */
    public function apply(array $entries, Collection $accounts): void;
}
