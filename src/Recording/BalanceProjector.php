<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Illuminate\Support\Collection;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Entry;

/**
 * BalanceProjector — applies just-persisted entries to the projection table
 * inside the same DB transaction that wrote them.
 *
 * Contract (binding on every implementation):
 *
 *   1. apply() is called by the TransactionRecorder INSIDE its DB::transaction
 *      closure, after the entries have been inserted but before commit. The
 *      recorder holds SELECT … FOR UPDATE locks on every account in $accounts.
 *   2. apply() MUST be synchronous and MUST NOT enqueue work, defer, or
 *      otherwise punt the projection update. The projection update must be
 *      durable when the recorder's DB transaction commits.
 *   3. apply() MUST be deterministic: given the same entries and accounts,
 *      it must produce the same projection update every time. The recorder
 *      may retry the closure on deadlock.
 *   4. apply() MUST NOT mutate transactions or entries — only the projection.
 *
 * The default DatabaseBalanceProjector performs an atomic per-account UPSERT
 * into the balances table. Substituting your own implementation is supported
 * (e.g. to project into a denormalised reporting table); switching to async
 * is NOT supported in v1 — the simpler synchronous contract is what keeps
 * ledger:verify a one-statement check.
 *
 * Whatever the implementation, ledger:verify must be able to confirm that
 * the projection equals SUM(signed entries) for every account.
 */
interface BalanceProjector
{
    /**
     * @param  list<Entry>  $entries  Entries already persisted in this transaction.
     * @param  Collection<string, Account>  $accounts  Locked accounts, keyed by id.
     */
    public function apply(array $entries, Collection $accounts): void;
}
