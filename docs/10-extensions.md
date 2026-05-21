# Extension Points

The package has exactly **five** extension points. Not six. The list is deliberately closed — the core stays small, and everything domain-specific lives in your application or in a companion package.

1. Custom Postings
2. Custom validators
3. Custom `BalanceProjector`
4. Custom `IdempotencyStore`
5. Event listeners

An extension can only ever **add** behaviour or checks. No extension can relax an invariant, remove a required validator, or write a financial model outside the recorder.

## 1. Custom Postings

This is the primary extension mechanism and accounts for almost all customisation. A Posting is a class in your application that extends `Syriable\Ledger\Postings\Posting`. You write one per business operation.

Everything about Postings — the contract, the determinism rules, the direction of value — is covered in [`04-the-posting-contract.md`](04-the-posting-contract.md). The cookbook in [`06-postings-cookbook.md`](06-postings-cookbook.md) is a gallery of real ones. Generate them with `php artisan make:posting`.

There is nothing to register. A Posting is just a class you instantiate and hand to `Ledger::post()`.

## 2. Custom validators

A validator is a pure check applied to every transaction draft before it is written. The package ships seven required validators; you can append your own.

### The contract

```php
namespace Syriable\Ledger\Validators;

interface TransactionValidator
{
    /**
     * @param  Collection<string, \Syriable\Ledger\Models\Account>  $accounts  Keyed by account id.
     */
    public function validate(TransactionDraft $draft, Collection $accounts): void;
}
```

A validator receives the draft and the **already-resolved** accounts collection (keyed by id). It either returns silently (pass) or throws a `LedgerException` subclass (fail).

### Rules for validators

- **Pure.** No database queries, no HTTP, no cache. Everything a validator needs is in its two arguments. The accounts are already loaded — do not re-query them.
- **Additive only.** Your validator can reject drafts the required validators would have accepted. It cannot *accept* drafts they would reject — they always run first.
- **Throw, don't return false.** Signal failure by throwing. A clear, specific exception message is part of the contract.

### Example — cap any single transaction at $10,000

```php
<?php

declare(strict_types=1);

namespace App\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Validators\TransactionValidator;

final class AmountCeilingValidator implements TransactionValidator
{
    private const CEILING = 1_000_000; // $10,000.00 in minor units

    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        $debits = 0;
        foreach ($draft->entries as $entry) {
            if ($entry->direction === EntryDirection::Debit) {
                $debits += $entry->amount->minorUnits;
            }
        }

        if ($debits > self::CEILING) {
            throw new \RuntimeException(
                "Transaction total {$debits} exceeds the ceiling of " . self::CEILING . '.'
            );
        }
    }
}
```

### Registering it

Add it to `config/ledger.php`:

```php
'validators' => [
    \App\Ledger\Validators\AmountCeilingValidator::class,
],
```

Validators listed here run **after** the seven required ones, in declared order. The required validators — minimum entries, positive amounts, single currency, ledger scope, currency match, account state, balanced — always run first and cannot be removed or reordered. This is enforced in the service provider; configuration can only append.

## 3. Custom `BalanceProjector`

The projector keeps the `balances` table in step with the entries. The default implementation updates it synchronously, inside the recorder's transaction. For very high write contention on a hot account you may want a different strategy — asynchronous via a queue, or a Redis-backed cache.

### The contract

```php
namespace Syriable\Ledger\Recording;

interface BalanceProjector
{
    /**
     * @param  list<\Syriable\Ledger\Models\Entry>  $entries  Entries already persisted in this transaction.
     * @param  Collection<string, \Syriable\Ledger\Models\Account>  $accounts  Locked accounts, keyed by id.
     */
    public function apply(array $entries, Collection $accounts): void;
}
```

### Registering it

Bind your implementation in a service provider:

```php
use Syriable\Ledger\Recording\BalanceProjector;
use App\Ledger\RedisBalanceProjector;

public function register(): void
{
    $this->app->bind(BalanceProjector::class, RedisBalanceProjector::class);
}
```

Whatever strategy you choose, `php artisan ledger:verify` must still be able to confirm that balances equal the entries sum. An asynchronous projector that lags is fine; a projector that produces *wrong* numbers is not. See [ADR 0001](adr/0001-projection-strategy.md).

## 4. Custom `IdempotencyStore`

The idempotency store answers "has a transaction with this reference already been recorded?" before the recorder does any work. The default reads the `transactions.reference` column directly. A Redis-backed store can make the replay short-circuit cheaper at high volume.

### The contract

```php
namespace Syriable\Ledger\Recording;

interface IdempotencyStore
{
    public function find(string $ledgerId, Reference $reference): ?Transaction;
}
```

### Registering it

```php
use Syriable\Ledger\Recording\IdempotencyStore;
use App\Ledger\RedisIdempotencyStore;

$this->app->bind(IdempotencyStore::class, RedisIdempotencyStore::class);
```

The store is an **optimisation**, not the safety net. The real guarantee is the `UNIQUE(ledger_id, reference)` database constraint, which catches a duplicate even if a custom store misses it. A custom store that returns `null` when it should not will simply make the recorder fall through to the constraint — slower, but never incorrect. See [ADR 0003](adr/0003-no-idempotency-keys-table.md).

## 5. Event listeners

Listen to `TransactionPosted`, `TransactionReversed`, `AccountOpened`, `AccountArchived` to trigger side effects — read models, notifications, search indexing, analytics.

Full event reference and the one hard rule (listeners must not write back to the ledger synchronously — queue a job instead) are in [`11-events-and-exceptions.md`](11-events-and-exceptions.md).

## The `Clock` (testing seam)

Not counted among the five extension points because it is an internal seam, but worth knowing: the package never calls `now()` directly — it depends on a `Clock` contract.

```php
namespace Syriable\Ledger\Recording;

interface Clock
{
    public function now(): CarbonImmutable;
}
```

In tests, bind a fixed clock to make `recorded_at` and default `posted_at` deterministic:

```php
use Syriable\Ledger\Recording\Clock;

$this->app->bind(Clock::class, fn () => new class implements Clock {
    public function now(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse('2026-01-01 12:00:00');
    }
});
```

See [`13-testing.md`](13-testing.md).

## What is NOT an extension point

These are intentionally closed. There is no supported way to:

- Replace the `TransactionRecorder`. It is `final`. It is the one writer, by design.
- Replace the validator pipeline wholesale. You append validators; you do not swap the pipeline.
- Remove or reorder a required validator.
- Write a financial model directly. The `WritableOnlyByRecorder` trait throws on any such attempt.
- Add a sixth method to the public facade. New operations are new Postings, not new verbs.

Anything beyond the five extension points belongs in a **companion package** — see [`12-anti-features.md`](12-anti-features.md) for the planned ones (tax, FX, wallets, reporting) and the reasoning.
