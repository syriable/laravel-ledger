# Operations

What to do, when, and what to be alarmed about.

## Rehearsing a deployment with `ledger:simulate`

Before you trust the ledger with real money, rehearse it. `ledger:simulate` drives a realistic marketplace lifecycle — orders, escrow, completions, partial refunds, reversals, payouts — through the **real** public API at volume, then verifies the result three independent ways.

```bash
php artisan ledger:simulate
php artisan ledger:simulate --sellers=200 --orders=20000
php artisan ledger:simulate --orders=5000 --seed=12345
```

It is a load-testing and confidence tool. Run it against a **scratch database** — it creates its own ledger and accounts; it is not meant to touch production data.

> **Refresh the database before every run.** `ledger:simulate` persists real transactions, and it generates idempotency references from sequential order ids (`sim.order.paid:order-1`, `sim.order.paid:order-2`, …). Those references are **not unique across runs** — a second run reuses the references from the first. Because the ledger is idempotent, every posting in the second run is then treated as a replay of the first; the simulation reports `Unexpected replay on first post …` and will fail (for example with `ReversalNotAllowedException` when it tries to reverse a transaction the previous run already reversed).
>
> Always start each run from a clean database:
>
> ```bash
> php artisan migrate:fresh
> php artisan ledger:simulate --sellers=200 --orders=20000
> ```
>
> If you cannot refresh the whole database, at minimum use a `--ledger` slug that has never been simulated into before — but `migrate:fresh` is the reliable choice. Run the simulator only against a disposable database.

### What it does

1. Bootstraps a ledger, platform accounts, and N seller accounts.
2. Simulates a stream of orders. Each order is paid; most complete (escrow → available); a few are partially refunded; a few are reversed as mistakes.
3. Runs end-of-run payouts for a share of sellers.
4. Deliberately re-posts a percentage of operations to exercise idempotency.

### How it verifies

The simulation only succeeds if **all three** of these pass:

1. **Shadow check.** The command keeps its own in-memory tally of what every balance _should_ be, computed without using the package. Every `balance()` (projection) and every `balanceAsOf(now())` (aggregation) must match that independent shadow.
2. **Zero-sum check.** Across the whole ledger, total debits must equal total credits.
3. **`ledger:verify`.** The standard integrity command must pass.

If any check fails, the command exits non-zero and prints exactly what diverged.

### Options

| Option            | Default  | Purpose                                         |
| ----------------- | -------- | ----------------------------------------------- |
| `--sellers`       | 50       | Seller accounts to create.                      |
| `--orders`        | 2000     | Orders to simulate.                             |
| `--currency`      | USD      | Simulation currency.                            |
| `--ledger`        | sim-main | Slug of the ledger to simulate into.            |
| `--seed`          | 20260101 | RNG seed — fix it for a reproducible run.       |
| `--complete-rate` | 85       | Percent of paid orders that complete.           |
| `--refund-rate`   | 8        | Percent of completed orders partially refunded. |
| `--reversal-rate` | 3        | Percent of paid orders reversed.                |
| `--payout-rate`   | 60       | Percent of sellers paid out at the end.         |
| `--replay-rate`   | 5        | Percent of postings deliberately re-posted.     |

Because the run is seeded, a failure is reproducible: re-run with the same `--seed` and the same scale to get the identical sequence. It also reports throughput (postings/second) and `ledger:verify` runtime — useful for spotting where verification slows down as data grows.

### When to run it

- Before a first production deployment — a green run is meaningful evidence the lifecycle logic is sound.
- In CI, at a modest scale, as a regression guard.
- After changing anything in `Recording/` or in your own Postings.

It does **not** prove concurrency safety — a single-process simulation cannot. That needs genuine parallel writers against PostgreSQL or MySQL.

## Daily verification

Run `ledger:verify` against production at least daily. Wire it into your scheduler:

```php
// app/Console/Kernel.php
$schedule->command('ledger:verify')->dailyAt('03:00')->onFailure(function () {
    // page the on-call engineer
});
```

The command checks three invariants per ledger:

1. Every transaction balances (`Σ debits == Σ credits`).
2. The ledger is zero-sum (`Σ all debits == Σ all credits`).
3. Every balance projection equals the entries sum.

Exit code 1 on any drift. If your monitoring is paging you, **stop processing payments and investigate**. Do not "fix" the projection until you understand why it drifted.

## When the projection drifts

The projection drifting from entries is a symptom, not a cause. Common causes:

| Symptom                                        | Likely cause                                               | Fix                                                                                               |
| ---------------------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| One account's projection is wrong              | A bug in a custom `BalanceProjector`, or a manual DB write | Rebuild balances; audit the custom projector                                                      |
| Many accounts off by the same delta            | A migration ran without using the recorder                 | Rebuild balances; never write to ledger tables outside the recorder                               |
| Zero-sum violation on a ledger                 | A corrupted entry insert (extremely rare)                  | **Stop the world.** Investigate the corruption with logs; do not rebuild before you understand it |
| `transactions.amount != Σ entries` for one row | Manual DB tampering                                        | Same as above                                                                                     |

### Rebuild the projection

If `verify` finds projection drift but the underlying entries are correct, rebuild:

```bash
php artisan ledger:rebuild-balances --ledger=platform-main
```

This is **safe** — entries are the source of truth, and the rebuild deterministically reproduces what the projection should be. It is wrapped in a DB transaction; a failure mid-rebuild leaves the projection unchanged.

## When entries themselves are wrong

Don't. They aren't allowed to be. If you find an entry that's wrong:

1. Identify the transaction it belongs to.
2. If the entire transaction was wrong: `Ledger::reverse($transaction)`.
3. If part of the transaction is wrong: post a new corrective Posting that compensates only the wrong portion.

**Do not** edit the entries table. If your team needs to do so under extraordinary circumstances, treat it the way a database team would treat an emergency `UPDATE` on a financial production table: two engineers present, a recorded session, and a post-mortem.

## Performance monitoring

The recorder's hot path is small. The four metrics worth watching:

- **Recorder latency** — time from `Ledger::post()` to `wasReplayed` being known. Should be a small number of ms on a healthy DB.
- **Deadlock retry rate** — how often the recorder's 3-attempt loop has to retry. A high rate means contention on a hot account; consider switching to an async projection.
- **`balances` upsert latency** — the projection step. Often the bottleneck on hot accounts.
- **`ledger:verify` runtime** — grows linearly with entries. If it climbs above a few minutes, partition `entries` by `posted_at` monthly.

## Batch posting

Use `Ledger::postMany(iterable<Posting>)` to record many Postings in a single DB transaction. Either all of them commit or none do, and the per-Posting transaction overhead disappears — important for legacy imports and high-throughput workers.

```php
$results = Ledger::postMany($postings);
```

Idempotency still applies per Posting: a Posting whose Reference already exists in the ledger returns `wasReplayed=true` and writes nothing. The deadlock-retry budget applies per Posting (as a savepoint inside the outer transaction).

If you want per-Posting atomicity (failures shouldn't roll back the rest of the batch), call `Ledger::post()` in a loop instead.

## Running under Octane / Swoole / RoadRunner

The recorder-window safety net (`WritableOnlyByRecorder`) is fiber-aware. Each Fiber gets its own depth counter via a `WeakMap`, so coroutines under Swoole/RoadRunner cannot share open windows. Code running on the main fiber (the default for PHP-FPM and Octane's single-request workers) shares one stable per-class sentinel, which is exactly the behaviour you want for sequential requests.

What this means in practice:

- **PHP-FPM** — unchanged behaviour; every request is the main fiber.
- **Octane (Swoole concurrent tasks, `Octane::concurrently(...)`)** — each task runs in its own Fiber and gets its own window. One task opening a recorder window will not let a parallel task `save()` a financial model.
- **Octane / RoadRunner request workers** — sequential requests in a single worker. Windows opened by the recorder are always closed in a `finally`, so depth returns to 0 at the end of every request.
- **Octane state-leak guarantee** — the package holds no other static mutable state that crosses requests.

If you author your own code that calls `Model::openRecorderWindow()` directly (you generally should not), make sure every `open` is paired with a `close` in a `finally` block.

## Backups and restore

Standard Laravel/Postgres/MySQL backup practices apply. There's nothing magic about ledger data — it's just immutable rows. A point-in-time restore brings the entire ledger back to a consistent moment, because every write is in a single DB transaction.

After a restore, run `ledger:verify`. If it passes, you're done. If it doesn't, you restored to a moment mid-write (extremely unusual) and need to investigate.

## Migration of legacy balances

Importing a pre-existing ledger from another system? Do not insert balances directly — write them in as **opening balance** transactions, so the books are internally consistent from the first day.

The package does not ship an opening-balance Posting; you write one, like any other Posting. It debits or credits the user account and books the opposite side against an explicit equity account (`equity.opening-balance.usd`):

```php
<?php

declare(strict_types=1);

namespace App\Ledger\Postings;

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Books a single account's opening balance against an equity account.
 * This example assumes a Liability target account (e.g. a user's wallet),
 * so the opening balance is a credit to the account and a debit to equity.
 */
final class OpeningBalancePosting extends Posting
{
    public function __construct(
        private readonly string $importKey,
        private readonly Account $account,        // the Liability account being seeded
        private readonly Account $openingEquity,  // equity.opening-balance.usd
        private readonly Money $amount,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->amount->currency; }
    public function reference(): Reference  { return Reference::for('legacy.opening-balance', $this->importKey); }

    public function entries(): array
    {
        return [
            EntryDraft::debit($this->openingEquity, $this->amount),  // Equity ↓
            EntryDraft::credit($this->account, $this->amount),       // Liability ↑
        ];
    }
}
```

Run it once per account being carried over:

```php
$scope  = Ledger::for('platform-main');
$equity = $scope->openAccount('equity.opening-balance.usd', AccountType::Equity, 'USD');

foreach ($legacyBalances as $userId => $minorUnits) {
    $user = User::findOrFail($userId);

    Ledger::post(new OpeningBalancePosting(
        importKey: (string) $userId,
        account: $user->account('available.usd'),
        openingEquity: $equity,
        amount: Money::of($minorUnits, 'USD'),
    ));
}
```

After the import, `equity.opening-balance.usd` holds the total of every balance you carried over, and the whole ledger is zero-sum. The `legacy.opening-balance:<userId>` reference makes the import idempotent — re-running it skips accounts already seeded.

Never insert rows into the `balances` table directly. The projection is downstream of entries; entries are the truth.
