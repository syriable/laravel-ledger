# Balances

An account's balance is **derived** from its entries — it is never stored as a primary fact. The package gives you two ways to read it, and you should understand the difference.

## Entries are the source of truth

There is no `accounts.balance` column. An account's balance *is* the signed sum of its entries. Everything else is a cache or a query over that truth.

This means: if the cache is ever wrong, the entries are still right, and the cache can be rebuilt from them. You can never get into a state where the "real" balance is lost.

## How a balance is computed

For each entry on the account, work out its **signed amount**:

- If the entry's direction **matches** the account's normal balance → the entry **increased** the account → `+amount`.
- If the entry's direction is the **opposite** of the normal balance → the entry **decreased** the account → `−amount`.

The balance is the sum of all signed amounts.

Worked example — a `platform.cash.usd` account (Asset, so normal balance = debit):

| Entry | Direction | Amount | Matches normal? | Signed |
| --- | --- | --- | --- | --- |
| Order paid | debit | 10 000 | yes | +10 000 |
| Payout settled | credit | 9 000 | no | −9 000 |
| **Balance** | | | | **+1 000** |

The account holds $10.00.

The `Account::signMultiplier()` method exposes this rule if you need it directly:

```php
$account->signMultiplier(EntryDirection::Debit);  // +1 for an Asset, -1 for a Liability
```

## Reading the current balance — the projection

```php
$cash = Ledger::for('platform-main')->account('platform.cash.usd');

$cash->balance();        // int — signed minor units, e.g. 1000
$cash->balanceMoney();   // Money — Money::of(1000, 'USD')
```

`balance()` reads the `balances` projection table. This is an **O(1) read** — one row lookup, no aggregation. The projection is updated by the recorder, atomically, inside the same database transaction that writes the entries. It is always consistent with the entries the instant the recorder commits.

### `balance()` vs `balanceMoney()`

`balance()` returns a **signed `int`**. It can be negative.

`balanceMoney()` returns a `Money` value object. Because `Money` cannot be negative, **`balanceMoney()` throws if the balance is below zero.**

```php
$cash->balance();       // -500  — fine, an int can be negative
$cash->balanceMoney();  // throws InvalidMoneyException if balance is -500
```

Use `balance()` when an account might legitimately go negative — see overdraft below. Use `balanceMoney()` when you know it cannot, or when you want the failure if it does.

### When is a balance negative?

A negative balance means the account is "underwater" relative to its normal side. Examples:

- An Asset cash account credited beyond its debits — you have paid out more than came in (an overdraft).
- A Liability account debited beyond its credits — you have released more than you held.

Whether that is a bug or a valid state is a question for *your* application, not the ledger. The ledger records what happened faithfully; it does not stop an account going negative. If you need to *prevent* it, add a custom validator — see [`10-extensions.md`](10-extensions.md).

## Reading a historical balance — aggregation

```php
use Carbon\CarbonImmutable;

$cash->balanceAsOf(CarbonImmutable::parse('2026-05-01 00:00:00'));  // int
```

`balanceAsOf($moment)` ignores the projection entirely. It aggregates directly over the `entries` table, summing the signed amounts of every entry with `posted_at <= $moment`.

It is **slower** than `balance()` — it scans entries rather than reading one cached row — but it is **authoritative** and answers a question the projection cannot: *what was this balance at some past point in time?*

| | `balance()` | `balanceAsOf($at)` |
| --- | --- | --- |
| Source | `balances` projection | `entries` table |
| Speed | O(1) — one row read | O(N) — aggregates entries |
| Answers | balance right now | balance at any past moment |
| Use for | live reads, UI, hot paths | audit, reporting, reconciliation |

`balanceAsOf(now())` and `balance()` always return the same number. If they ever disagree, the projection has drifted — see below.

## `posted_at` vs `recorded_at`

Two timestamps live on every transaction, and `balanceAsOf` uses the first:

- **`posted_at`** — *business time*. When the economic event happened. Caller-supplied; a Posting may backdate it by overriding `postedAt()`.
- **`recorded_at`** — *system time*. When the recorder actually wrote the row. Always "now", set by the package.

`balanceAsOf` filters on `posted_at`, because you almost always want "the balance as of this business date", not "as of when the row happened to be inserted". Keep this in mind if you backdate postings: a backdated transaction changes historical balances.

## The projection can be rebuilt

Because entries are the truth, the projection is disposable. Two artisan commands manage it:

```bash
php artisan ledger:verify              # check projection == entries for every account
php artisan ledger:rebuild-balances    # truncate and rebuild the projection from entries
```

`ledger:verify` exits non-zero if any projection has drifted from its entries. Run it in CI and on a daily schedule. If it ever reports drift, `ledger:rebuild-balances` heals it — safely, because it just recomputes from the authoritative entries. Both commands accept `--ledger=slug` to scope to one ledger.

See [`09-operations.md`](09-operations.md) for the operational playbook around drift.

## Projection strategy

The default projection is **synchronous** — updated inside the recorder's transaction. For most applications this is correct and you never think about it.

For very high write throughput on a single hot account, the synchronous projection becomes a contention point. The `BalanceProjector` contract is swappable precisely so you can switch to an asynchronous or cached strategy without changing anything else. See [`10-extensions.md`](10-extensions.md) and [ADR 0001](adr/0001-projection-strategy.md).
