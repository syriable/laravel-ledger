<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/ledger-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="art/ledger-light.svg">
    <img alt="Laravel Ledger Logo" src="art/ledger-light.svg" width="600">
  </picture>
</p>

<p align="center">
<a href="https://packagist.org/packages/syriable/laravel-ledger"><img src="https://img.shields.io/packagist/v/syriable/laravel-ledger.svg?style=flat-square" alt="Latest Version on Packagist"></a>
<a href="https://github.com/syriable/laravel-ledger/actions?query=workflow%3Arun-tests+branch%3Amain"><img src="https://img.shields.io/github/actions/workflow/status/syriable/laravel-ledger/run-tests.yml?branch=main&label=tests&style=flat-square" alt="GitHub Tests Action Status"></a>
<a href="https://github.com/syriable/laravel-ledger/actions?query=workflow%3A%22Fix+PHP+code+style+issues%22+branch%3Amain"><img src="https://img.shields.io/github/actions/workflow/status/syriable/laravel-ledger/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square" alt="GitHub Code Style Action Status"></a>
<a href="https://github.com/syriable/laravel-ledger/actions?query=workflow%3A%22PHPStan%22+branch%3Amain"><img src="https://img.shields.io/github/actions/workflow/status/syriable/laravel-ledger/phpstan.yml?branch=main&label=phpstan&style=flat-square" alt="GitHub PHPStan Action Status"></a>
<a href="https://packagist.org/packages/syriable/laravel-ledger"><img src="https://img.shields.io/packagist/dt/syriable/laravel-ledger.svg?style=flat-square" alt="Total Downloads"></a>
</p>

An immutable, append-only, double-entry financial ledger engine for Laravel. Strongly opinionated, minimal core, strict invariants. It records balanced double-entry transactions atomically and idempotently, and reads them back accurately — and it refuses to do anything that would let your books drift.

```php
use Syriable\Ledger\Facades\Ledger;

Ledger::createLedger(slug: 'platform-main', currency: 'USD');

$result = Ledger::post(new OrderPaidPosting($order));

$result->transaction;   // the recorded Transaction
$result->wasReplayed;   // true if this reference was already posted
```

## Installation

You can install the package via Composer:

```bash
composer require syriable/laravel-ledger
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="ledger-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="ledger-config"
```

This is the contents of the published config file:

```php
return [

    // If your app uses a single ledger, set its slug here and the
    // HasAccounts trait will resolve it automatically.
    'default_ledger_slug' => env('LEDGER_DEFAULT_SLUG'),

    // Override table names if they collide with existing tables.
    'table_names' => [
        'ledgers' => 'ledgers',
        'accounts' => 'accounts',
        'transactions' => 'transactions',
        'entries' => 'entries',
        'balances' => 'balances',
    ],

    // The package's required validators always run first and cannot be
    // removed. Anything listed here is appended after the required set.
    'validators' => [
        // \App\Ledger\Validators\AmountCeilingValidator::class,
    ],

];
```

## Usage

The public surface is three verbs: **open**, **post**, **reverse**.

### Open a ledger and accounts

```php
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Facades\Ledger;

Ledger::createLedger(slug: 'platform-main', currency: 'USD');

$cash = Ledger::for('platform-main')->openAccount(
    code: 'platform.cash.usd',
    type: AccountType::Asset,
    currency: 'USD',
);

$revenue = Ledger::for('platform-main')->openAccount(
    code: 'platform.revenue.usd',
    type: AccountType::Revenue,
    currency: 'USD',
);
```

### Define a Posting

A `Posting` is the one and only way to write to the ledger. It is a deterministic domain operation that produces a balanced transaction.

```bash
php artisan make:posting OrderPaidPosting
```

```php
use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

final class OrderPaidPosting extends Posting
{
    /**
     * Accounts and amounts are resolved by the caller and passed in.
     * A Posting must never query the database inside entries() — that
     * would break determinism on retry. See docs/04-the-posting-contract.md.
     */
    public function __construct(
        private readonly string $orderId,
        private readonly Account $cash,
        private readonly Account $revenue,
        private readonly Money $total,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->total->currency; }
    public function reference(): Reference  { return Reference::for('order.paid', $this->orderId); }

    public function entries(): array
    {
        return [
            EntryDraft::debit($this->cash, $this->total),
            EntryDraft::credit($this->revenue, $this->total),
        ];
    }
}
```

### Post it

```php
$scope   = Ledger::for('platform-main');
$cash    = $scope->account('platform.cash.usd');
$revenue = $scope->account('platform.revenue.usd');

$result = Ledger::post(new OrderPaidPosting(
    orderId: $order->id,
    cash: $cash,
    revenue: $revenue,
    total: Money::of(9_900, 'USD'),
));
```

Posting is idempotent on the `Reference`. Posting the same operation twice returns the original transaction with `wasReplayed = true` — no duplicate write.

### Reverse it

```php
Ledger::reverse($result->transaction, reason: 'chargeback');
```

A reversal is a new, immutable transaction that inverts the original. A transaction can be reversed at most once, and a reversal cannot itself be reversed — both enforced at the database level. For partial refunds, post a new operation rather than reversing.

### Read balances

```php
$cash = Ledger::for('platform-main')->account('platform.cash.usd');

$cash->balance();              // int — signed balance (negative = overdraft)
$cash->balanceMoney();         // Money
$cash->balanceAsOf($moment);   // int — historical balance, from entries
$cash->entries;                // immutable history
```

### Owner-side ergonomics

Apply the `HasAccounts` trait to any model that owns accounts:

```php
use Syriable\Ledger\HasAccounts;

class User extends Model
{
    use HasAccounts;

    public function defaultLedgerSlug(): string
    {
        return 'platform-main';
    }
}

$user->openAccount('available.usd', AccountType::Liability, 'USD');
$user->account('available.usd')->balance();
```

### Verify integrity

```bash
php artisan ledger:verify                    # all ledgers
php artisan ledger:verify --ledger=platform-main
php artisan ledger:rebuild-balances          # rebuild projections from entries
php artisan ledger:simulate                  # rehearse a marketplace at volume
```

`ledger:verify` checks that every transaction balances, every ledger is zero-sum, and every balance projection matches the entries. It exits non-zero on drift — wire it into your scheduler and your CI.

`ledger:simulate` drives a realistic marketplace lifecycle through the real API at volume and verifies the result against an independent shadow ledger — a one-command way to stress-test the package before trusting it with real money. Run it only against a disposable database, and run `php artisan migrate:fresh` before each run to avoid reference collisions with previous runs. See [`docs/09-operations.md`](docs/09-operations.md#rehearsing-a-deployment-with-ledgersimulate).

## Core invariants

These are enforced by validators and database constraints. They cannot be relaxed by configuration.

- **Append-only** — no financial column on `transactions` or `entries` is ever mutated.
- **Always balanced** — `Σ debits == Σ credits` per transaction.
- **Single currency per transaction** — FX is two linked postings.
- **Entry currency matches account currency.**
- **No cross-ledger entries.**
- **Amounts are positive integers** — direction is encoded by Debit/Credit.
- **Every transaction has a unique idempotency reference.**
- **Archived accounts reject new entries** (reversals exempted).
- **A transaction can be reversed at most once; reversals cannot be reversed.**
- **No soft-deletes** on financial models.
- **Money never crosses the float boundary.**

See [`docs/03-invariants.md`](docs/03-invariants.md) for the full list and what enforces each one.

## Documentation

Full documentation lives in the [`docs/`](docs/) directory. Start with the [documentation index](docs/README.md).

If you have never worked with a double-entry ledger, read [**The Posting Contract & Direction of Value**](docs/04-the-posting-contract.md) first — it explains debit/credit, normal balances, and the rules a Posting must follow, from scratch.

- [Introduction](docs/01-introduction.md) — why this package exists.
- [Concepts](docs/02-concepts.md) — the seven concepts the package is built from.
- [Invariants](docs/03-invariants.md) — the financial rules and what enforces each.
- [The Posting Contract](docs/04-the-posting-contract.md) — debit/credit direction and the determinism rules.
- [Installation & Quickstart](docs/05-installation-and-quickstart.md) — the full lifecycle in five minutes.
- [Postings Cookbook](docs/06-postings-cookbook.md) — a marketplace, worked end to end.
- [Reversals vs Refunds](docs/07-reversals-and-refunds.md) — the distinction that causes the most bugs.
- [Balances](docs/08-balances.md) — projection vs aggregation, signed balances, overdrafts.
- [Operations](docs/09-operations.md) — verify, rebuild, drift recovery, legacy imports.
- [Extension Points](docs/10-extensions.md) — the five ways to extend the package.
- [Events & Exceptions](docs/11-events-and-exceptions.md) — every event and exception.
- [Anti-features](docs/12-anti-features.md) — what the package will never do.
- [Testing](docs/13-testing.md) — how to test a system built on the package.
- [FAQ](docs/14-faq.md) — quick answers to common questions.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details — including the explicit list of changes that will be rejected, because this package's invariants are the reason it exists.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Syriable](https://github.com/syriable)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
