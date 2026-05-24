# Installation & Quickstart

## Requirements

- PHP 8.3 or higher
- Laravel 11 or 12
- PostgreSQL, MySQL 8+, or SQLite 3.31+

PostgreSQL is recommended for production. MySQL 8 and SQLite are fully supported; SQLite is the default for the test suite.

## Install

```bash
composer require syriable/laravel-ledger
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="ledger-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="ledger-config"
```

This creates `config/ledger.php`. See [`config-reference`](#configuration) below for what each option does.

## The five-minute quickstart

This walks through the complete lifecycle: create a ledger, open accounts, post a transaction, read balances, reverse it.

### 1. Create a ledger

A ledger is a bounded financial context. Most applications have one.

```php
use Syriable\Ledger\Facades\Ledger;

Ledger::createLedger(slug: 'platform-main', currency: 'USD');
```

`createLedger` is idempotent — calling it again with the same slug returns the existing ledger. Safe to put in a seeder.

### 2. Open accounts

```php
use Syriable\Ledger\Enums\AccountType;

$scope = Ledger::for('platform-main');

$cash = $scope->openAccount(
    code: 'platform.cash.usd',
    type: AccountType::Asset,
    currency: 'USD',
);

$revenue = $scope->openAccount(
    code: 'platform.revenue.usd',
    type: AccountType::Revenue,
    currency: 'USD',
);
```

`openAccount` is idempotent on `(ledger, code)`. The account `code` is your stable handle — dotted, lowercase. The `normal_balance` is derived automatically from the `type`.

### 3. Write a Posting

A Posting is a class describing one business operation. Generate one:

```bash
php artisan make:posting RecordSalePosting
```

Then fill it in:

```php
<?php

declare(strict_types=1);

namespace App\Ledger\Postings;

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

final class RecordSalePosting extends Posting
{
    public function __construct(
        private readonly string $saleId,
        private readonly Account $cash,
        private readonly Account $revenue,
        private readonly Money $amount,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->amount->currency; }
    public function reference(): Reference  { return Reference::for('sale.recorded', $this->saleId); }

    public function entries(): array
    {
        return [
            EntryDraft::debit($this->cash, $this->amount),    // Asset ↑
            EntryDraft::credit($this->revenue, $this->amount),// Revenue ↑
        ];
    }
}
```

If the debit/credit choices are unclear, read [`04-the-posting-contract.md`](04-the-posting-contract.md) — it explains direction of value from scratch.

### 4. Post it

```php
use App\Ledger\Postings\RecordSalePosting;

$scope = Ledger::for('platform-main');

$result = Ledger::post(new RecordSalePosting(
    saleId: 'sale-1001',
    cash: $scope->account('platform.cash.usd'),
    revenue: $scope->account('platform.revenue.usd'),
    amount: Money::of(2_500, 'USD'),  // $25.00
));

$result->transaction;   // the recorded Transaction model
$result->wasReplayed;   // false on first post, true on a duplicate
```

### 5. Read balances

```php
$cash = Ledger::for('platform-main')->account('platform.cash.usd');

$cash->balance();              // 2500 — signed integer, minor units
$cash->balanceMoney();         // Money::of(2500, 'USD')
$cash->balanceAsOf($moment);   // historical balance from entries
$cash->entries;                // the account's immutable entry history
```

See [`08-balances.md`](08-balances.md) for the difference between `balance()` and `balanceAsOf()`.

### 6. Reverse it (if it was a mistake)

```php
Ledger::reverse($result->transaction, reason: 'recorded in error');
```

A reversal is a new, immutable transaction that inverts the original. For partial undo, post a new Posting instead — see [`07-reversals-and-refunds.md`](07-reversals-and-refunds.md).

### 7. Verify integrity

```bash
php artisan ledger:verify
```

Confirms every transaction balances, every ledger is zero-sum, and every projection matches the entries. Run it in CI and on a daily schedule.

## Using the HasAccounts trait

If accounts belong to a model — a `User`, an `Order`, a `Wallet` — apply the `HasAccounts` trait:

```php
use Illuminate\Database\Eloquent\Model;
use Syriable\Ledger\HasAccounts;

class User extends Model
{
    use HasAccounts;

    public function defaultLedgerSlug(): string
    {
        return 'platform-main';
    }
}
```

Now the model can open and retrieve its own accounts:

```php
$user->openAccount('available.usd', AccountType::Liability, 'USD');
$user->account('available.usd');           // retrieve
$user->account('available.usd')->balance();
$user->accounts;                           // all of this model's accounts
```

`defaultLedgerSlug()` is optional. If you omit it, either pass `ledgerSlug:` explicitly to `openAccount()`, or set `default_ledger_slug` in the config.

## Configuration

The published `config/ledger.php` (defaults shown):

```php
return [

    // The ledger slug HasAccounts uses when no slug is given explicitly.
    // Leave null for multi-ledger apps and always pass the slug.
    'default_ledger_slug' => env('LEDGER_DEFAULT_SLUG'),

    // Table names. Override only if they collide with existing tables.
    'table_names' => [
        'ledgers' => 'ledgers',
        'accounts' => 'accounts',
        'transactions' => 'transactions',
        'entries' => 'entries',
        'balances' => 'balances',
    ],

    // Extra validators, appended AFTER the eight required ones.
    // Required validators always run first and cannot be removed.
    'validators' => [
        // \App\Ledger\Validators\AmountCeilingValidator::class,
    ],

    // Maximum future skew tolerated on a Posting's posted_at, in seconds.
    // Protects every balanceAsOf() query from clock-skew or buggy backdating.
    // Set 0 to forbid any future-dated postings.
    'max_clock_skew_seconds' => env('LEDGER_MAX_CLOCK_SKEW_SECONDS', 300),

    // Optional inclusive lower bound for posted_at. Accepts null, an
    // ISO-8601 string, or a callable returning a DateTimeInterface.
    'historical_lower_bound' => env('LEDGER_HISTORICAL_LOWER_BOUND'),

    // TransactionRecorder tunables. Defaults suit almost every workload.
    'recorder' => [
        'max_attempts' => (int) env('LEDGER_RECORDER_MAX_ATTEMPTS', 3),
    ],

];
```

| Option | Type | Purpose |
| --- | --- | --- |
| `default_ledger_slug` | `?string` | Ledger slug resolved by `HasAccounts` when none is passed. |
| `table_names.*` | `string` | Per-table name overrides. Rarely needed. |
| `validators` | `array<class-string>` | Additional `TransactionValidator`s, run after the required set. See [`10-extensions.md`](10-extensions.md). |
| `max_clock_skew_seconds` | `int` | Max seconds a Posting's `posted_at` may be in the future. Default `300`. |
| `historical_lower_bound` | `null\|string\|callable` | Optional floor on `posted_at`. Prevents backdating below a known point. |
| `recorder.max_attempts` | `int` | Deadlock-retry budget for a single Posting. Default `3`. |

## Next steps

- [`02-concepts.md`](02-concepts.md) — the seven concepts the package is built from.
- [`04-the-posting-contract.md`](04-the-posting-contract.md) — debit/credit direction, the determinism rules.
- [`06-postings-cookbook.md`](06-postings-cookbook.md) — a full marketplace lifecycle, worked end to end.
