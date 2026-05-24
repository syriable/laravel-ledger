# Testing

How the package is tested, and how to test a financial system built on top of it.

## Testing the package itself

The package's own suite uses [Pest](https://pestphp.com/) with Orchestra Testbench, against an in-memory SQLite database. Run it:

```bash
composer test
```

It is organised in three rings, each stricter than the last.

### Ring 1 — Unit

Pure-PHP tests for the value layer and the validators. No database. Fast.

- `Money` arithmetic — addition, subtraction, currency rules, refusal of negatives and floats.
- `Reference` — scope format enforcement, part handling.
- `AccountCode` — format enforcement.
- Each of the seven validators — one happy-path test and one failure-mode test per branch.

### Ring 2 — Feature

Tests against a real (SQLite) database, exercising the recorder end to end.

- **Happy path** — a balanced posting persists transaction, entries, and projection.
- **Replay** — the same reference posted twice yields one transaction; the second result has `wasReplayed = true`.
- **Imbalance rejection** — an unbalanced posting throws and writes nothing.
- **Mixed-currency rejection.**
- **Archived-account rejection.**
- **Reversal round trip** — reversing a transaction returns every account to its pre-posting balance.
- **Double-reversal blocked** — reversing the same transaction twice throws.
- **Reversal-of-reversal blocked.**
- **Direct-write protection** — `save()`/`update()`/`delete()` on a financial model outside the recorder throws.

### Ring 3 — Invariant checks

After the feature tests, custom Pest expectations assert the package's invariants hold over whatever data the tests produced:

```php
expect($transaction)->toBeBalanced();              // Σ debits == Σ credits
expect($ledger)->toHaveZeroSum();                  // Σ all debits == Σ all credits
expect($ledger)->toHaveBalancesEqualEntries();     // projection == aggregated entries
```

`toHaveBalancesEqualEntries()` is the most important one — it is the same check `ledger:verify` performs, run as an assertion.

## Testing your application

When you build on the package, you write Postings. Test them.

### Test that a Posting balances and books correctly

```php
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\ValueObjects\Money;
use App\Ledger\Postings\OrderPaidPosting;

it('books an order payment correctly', function (): void {
    Ledger::createLedger(slug: 'platform-main', currency: 'USD');
    $scope = Ledger::for('platform-main');

    $cash    = $scope->openAccount('platform.cash.usd',   AccountType::Asset,   'USD');
    $escrow  = $scope->openAccount('escrow.usd',          AccountType::Liability, 'USD');
    $revenue = $scope->openAccount('platform.revenue.usd',AccountType::Revenue, 'USD');

    $result = Ledger::post(new OrderPaidPosting(
        orderId: 'order-1',
        cash: $cash,
        sellerEscrow: $escrow,
        commissionRevenue: $revenue,
        total: Money::of(10_000, 'USD'),
        sellerNet: Money::of(9_000, 'USD'),
        commission: Money::of(1_000, 'USD'),
    ));

    expect($result->wasReplayed)->toBeFalse()
        ->and($result->transaction)->toBeBalanced();

    expect($cash->fresh()->balance())->toBe(10_000)      // Asset, debited
        ->and($escrow->fresh()->balance())->toBe(9_000)  // Liability, credited
        ->and($revenue->fresh()->balance())->toBe(1_000);
});
```

The balance signs follow the rules in [`04-the-posting-contract.md`](04-the-posting-contract.md): debiting an Asset and crediting a Liability or Revenue all produce positive balances.

### Test idempotency

```php
it('absorbs a duplicate posting', function (): void {
    // ... set up ledger and accounts ...

    $first  = Ledger::post(new OrderPaidPosting(orderId: 'order-1', /* ... */));
    $second = Ledger::post(new OrderPaidPosting(orderId: 'order-1', /* ... */));

    expect($first->wasReplayed)->toBeFalse()
        ->and($second->wasReplayed)->toBeTrue()
        ->and($second->transaction->id)->toBe($first->transaction->id);
});
```

### Test a reversal

```php
it('reversal restores the prior balances', function (): void {
    // ... post an OrderPaidPosting, capture $result ...

    Ledger::reverse($result->transaction, reason: 'test');

    expect($cash->fresh()->balance())->toBe(0)
        ->and($escrow->fresh()->balance())->toBe(0)
        ->and($revenue->fresh()->balance())->toBe(0);
});
```

### Freeze time with a fixed Clock

The package reads "now" only through the `Clock` contract. Two equally valid strategies:

**1. `Carbon::setTestNow()` (simplest).** The default `SystemClock` returns `CarbonImmutable::now()`, which honours Carbon's global test override. This freezes every package timestamp — `recorded_at`, default `posted_at`, `archived_at` — without binding a custom service:

```php
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(); // reset so other tests aren't affected
});
```

**2. Bind a custom `Clock` (deterministic, Carbon-free).** Use this when you want a monotonic counter or any non-Carbon time source:

```php
use Syriable\Ledger\Recording\Clock;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->app->bind(Clock::class, fn () => new class implements Clock {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-01-01 12:00:00');
        }
    });
});
```

For testing `balanceAsOf()` and other history-sensitive logic, prefer to set `posted_at` explicitly. Give your Posting an optional `postedAt` constructor argument and override the `postedAt()` method to return it — then your tests control business time directly, without depending on wall-clock timing.

### Assert your own invariants

The package's custom expectations are available to your suite too. After any sequence of postings:

```php
expect($ledger)->toHaveZeroSum()
    ->and($ledger)->toHaveBalancesEqualEntries();
```

If either fails, a Posting has a bug — most likely a debit/credit direction error that still happened to balance numerically.

## Recommended practice

- **Test every Posting.** Each is a piece of accounting logic. A wrong debit/credit can balance and still be wrong — only a balance assertion catches it.
- **Run `ledger:verify` in CI.** After your test suite, run it against the test database. If it exits non-zero, a posting corrupted the books.
- **Property-test the recorder if you customise it.** If you bind a custom `BalanceProjector` or `IdempotencyStore`, post many random valid transactions and assert `toHaveZeroSum()` and `toHaveBalancesEqualEntries()` still hold.
- **Static analysis.** The package ships a PHPStan (Larastan) configuration at level 9. Run `composer analyse` and keep your own Postings clean at the same level.
