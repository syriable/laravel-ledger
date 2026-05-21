# Operations

What to do, when, and what to be alarmed about.

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

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| One account's projection is wrong | A bug in a custom `BalanceProjector`, or a manual DB write | Rebuild balances; audit the custom projector |
| Many accounts off by the same delta | A migration ran without using the recorder | Rebuild balances; never write to ledger tables outside the recorder |
| Zero-sum violation on a ledger | A corrupted entry insert (extremely rare) | **Stop the world.** Investigate the corruption with logs; do not rebuild before you understand it |
| `transactions.amount != Σ entries` for one row | Manual DB tampering | Same as above |

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
