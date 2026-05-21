# Postings Cookbook — Marketplace Lifecycle

A realistic chart of accounts for a marketplace, end-to-end. Every Posting below is **deterministic** and **idempotent** — re-running with the same inputs is a no-op.

The golden rule running through every example: **a Posting never queries the database.** Accounts are resolved by the caller and passed into the Posting's constructor. See [`04-the-posting-contract.md`](04-the-posting-contract.md) for why.

## Chart of accounts

```
Platform-side
  platform.cash.usd                       Asset
  platform.payable.usd                    Liability   (money owed to processors)
  platform.revenue.commission.usd         Revenue

Per seller (a User model)
  escrow.usd                              Liability   (held, not yet released)
  available.usd                           Liability   (released, withdrawable)
```

Open them once, at bootstrap. `createLedger` and `openAccount` are both idempotent, so this block is safe to run repeatedly (e.g. from a seeder).

```php
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Facades\Ledger;

Ledger::createLedger(slug: 'platform-main', currency: 'USD');

$scope = Ledger::for('platform-main');
$scope->openAccount('platform.cash.usd',               AccountType::Asset,     'USD');
$scope->openAccount('platform.payable.usd',            AccountType::Liability, 'USD');
$scope->openAccount('platform.revenue.commission.usd', AccountType::Revenue,   'USD');
```

Per-seller accounts are opened through the `HasAccounts` trait on `User`:

```php
$seller->openAccount('escrow.usd',    AccountType::Liability, 'USD');
$seller->openAccount('available.usd', AccountType::Liability, 'USD');
```

## Why these debits and credits?

Before the code, the accounting. If you have no bookkeeping background, read [`04-the-posting-contract.md`](04-the-posting-contract.md) first — it explains debit/credit direction in full. The one-line version:

- **Debit increases** Assets and Expenses; **debit decreases** Liabilities, Equity, Revenue.
- **Credit increases** Liabilities, Equity, Revenue; **credit decreases** Assets and Expenses.

Every transaction below balances: total debits equal total credits.

## 1 — Buyer pays for an order

Cash arrives in the platform's cash account (an Asset → **debit** to increase it). The seller's net is owed to them, held in escrow (a Liability → **credit** to increase it). The platform's commission is earned revenue (Revenue → **credit** to increase it).

```php
use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

final class OrderPaidPosting extends Posting
{
    public function __construct(
        private readonly string $orderId,
        private readonly Account $cash,
        private readonly Account $sellerEscrow,
        private readonly Account $commissionRevenue,
        private readonly Money $total,
        private readonly Money $sellerNet,
        private readonly Money $commission,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->total->currency; }
    public function reference(): Reference  { return Reference::for('order.paid', $this->orderId); }
    public function description(): ?string  { return "Order {$this->orderId} paid"; }

    public function entries(): array
    {
        return [
            EntryDraft::debit($this->cash, $this->total),
            EntryDraft::credit($this->sellerEscrow, $this->sellerNet),
            EntryDraft::credit($this->commissionRevenue, $this->commission),
        ];
    }
}
```

Calling it — the caller resolves accounts and computes amounts up front:

```php
$scope  = Ledger::for('platform-main');
$seller = $order->seller;

Ledger::post(new OrderPaidPosting(
    orderId: $order->id,
    cash: $scope->account('platform.cash.usd'),
    sellerEscrow: $seller->account('escrow.usd'),
    commissionRevenue: $scope->account('platform.revenue.commission.usd'),
    total: Money::of(10_000, 'USD'),       // $100.00
    sellerNet: Money::of(9_000, 'USD'),    // $90.00
    commission: Money::of(1_000, 'USD'),   // $10.00
));
```

Debits: 10 000. Credits: 9 000 + 1 000 = 10 000. Balanced.

## 2 — Order completes: escrow becomes available

The seller's money moves from "held" to "withdrawable". Both accounts are Liabilities. Escrow **decreases** (debit a Liability), available **increases** (credit a Liability).

```php
final class OrderCompletedPosting extends Posting
{
    public function __construct(
        private readonly string $orderId,
        private readonly Account $sellerEscrow,
        private readonly Account $sellerAvailable,
        private readonly Money $sellerNet,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->sellerNet->currency; }
    public function reference(): Reference  { return Reference::for('order.completed', $this->orderId); }

    public function entries(): array
    {
        return [
            EntryDraft::debit($this->sellerEscrow, $this->sellerNet),
            EntryDraft::credit($this->sellerAvailable, $this->sellerNet),
        ];
    }
}
```

```php
$seller = $order->seller;

Ledger::post(new OrderCompletedPosting(
    orderId: $order->id,
    sellerEscrow: $seller->account('escrow.usd'),
    sellerAvailable: $seller->account('available.usd'),
    sellerNet: Money::of(9_000, 'USD'),
));
```

## 3 — Seller requests and receives a payout

Two postings. The request moves money from the seller's available balance into the platform's payables; the settlement pays it out as real cash.

```php
final class PayoutInitiatedPosting extends Posting
{
    public function __construct(
        private readonly string $payoutId,
        private readonly Account $sellerAvailable,
        private readonly Account $platformPayable,
        private readonly Money $amount,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->amount->currency; }
    public function reference(): Reference  { return Reference::for('payout.initiated', $this->payoutId); }

    public function entries(): array
    {
        return [
            // available (Liability) decreases → debit
            EntryDraft::debit($this->sellerAvailable, $this->amount),
            // platform payable (Liability) increases → credit
            EntryDraft::credit($this->platformPayable, $this->amount),
        ];
    }
}

final class PayoutSettledPosting extends Posting
{
    public function __construct(
        private readonly string $payoutId,
        private readonly Account $platformPayable,
        private readonly Account $platformCash,
        private readonly Money $amount,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->amount->currency; }
    public function reference(): Reference  { return Reference::for('payout.settled', $this->payoutId); }

    public function entries(): array
    {
        return [
            // payable (Liability) decreases → debit
            EntryDraft::debit($this->platformPayable, $this->amount),
            // cash (Asset) decreases → credit
            EntryDraft::credit($this->platformCash, $this->amount),
        ];
    }
}
```

```php
$scope  = Ledger::for('platform-main');
$seller = $payout->seller;
$amount = Money::of(9_000, 'USD');

Ledger::post(new PayoutInitiatedPosting(
    payoutId: $payout->id,
    sellerAvailable: $seller->account('available.usd'),
    platformPayable: $scope->account('platform.payable.usd'),
    amount: $amount,
));

// ... later, when the bank confirms the transfer settled:

Ledger::post(new PayoutSettledPosting(
    payoutId: $payout->id,
    platformPayable: $scope->account('platform.payable.usd'),
    platformCash: $scope->account('platform.cash.usd'),
    amount: $amount,
));
```

## 4 — Full refund of an order

If the order should never have been paid (fraud, error), reverse the original transaction. A reversal is a new, immutable, automatically-balanced transaction that inverts every entry.

```php
use Syriable\Ledger\Models\Transaction;

// You stored the transaction id from the OrderPaidPosting result.
$original = Transaction::query()
    ->where('ledger_id', $ledger->id)
    ->where('reference', "order.paid:{$order->id}")
    ->firstOrFail();

Ledger::reverse($original, reason: 'buyer refund');
```

You do **not** write a posting for a full reversal — `Ledger::reverse()` builds it for you. See [`07-reversals-and-refunds.md`](07-reversals-and-refunds.md).

## 5 — Partial refund

A partial refund is a **new economic event**, not a reversal. The original `OrderPaidPosting` was correct; today you are refunding part of it and clawing back part of the commission.

```php
final class PartialRefundPosting extends Posting
{
    public function __construct(
        private readonly string $refundId,
        private readonly Account $sellerEscrow,
        private readonly Account $commissionRevenue,
        private readonly Account $platformCash,
        private readonly Money $refundAmount,
        private readonly Money $commissionClaw,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->refundAmount->currency; }
    public function reference(): Reference  { return Reference::for('order.refunded.partial', $this->refundId); }

    public function entries(): array
    {
        return [
            // take the refund back out of the seller's escrow (Liability ↓ → debit)
            EntryDraft::debit($this->sellerEscrow, $this->refundAmount),
            // claw back the commission we recognised (Revenue ↓ → debit)
            EntryDraft::debit($this->commissionRevenue, $this->commissionClaw),
            // pay the buyer back from cash (Asset ↓ → credit)
            EntryDraft::credit($this->platformCash, $this->refundAmount->plus($this->commissionClaw)),
        ];
    }
}
```

```php
$scope  = Ledger::for('platform-main');
$seller = $order->seller;

Ledger::post(new PartialRefundPosting(
    refundId: $refund->id,
    sellerEscrow: $seller->account('escrow.usd'),
    commissionRevenue: $scope->account('platform.revenue.commission.usd'),
    platformCash: $scope->account('platform.cash.usd'),
    refundAmount: Money::of(3_000, 'USD'),     // $30 back to the buyer
    commissionClaw: Money::of(300, 'USD'),     // $3 of commission reversed
));
```

Debits: 3 000 + 300 = 3 300. Credits: 3 300. Balanced.

## 6 — Stripe webhook (idempotency in practice)

Stripe delivers events with at-least-once semantics — the same webhook can arrive twice. Use the Stripe event id as the reference scope's value, and replays are absorbed automatically.

```php
final class StripeChargeSucceededPosting extends Posting
{
    public function __construct(
        private readonly string $stripeEventId,
        private readonly Account $cash,
        private readonly Account $sellerEscrow,
        private readonly Account $commissionRevenue,
        private readonly Money $total,
        private readonly Money $sellerNet,
        private readonly Money $commission,
    ) {}

    public function ledger(): string       { return 'platform-main'; }
    public function currency(): string     { return $this->total->currency; }

    public function reference(): Reference
    {
        // The event id makes this posting idempotent against webhook retries.
        return Reference::for('stripe.charge_succeeded', $this->stripeEventId);
    }

    public function entries(): array
    {
        return [
            EntryDraft::debit($this->cash, $this->total),
            EntryDraft::credit($this->sellerEscrow, $this->sellerNet),
            EntryDraft::credit($this->commissionRevenue, $this->commission),
        ];
    }
}
```

When the duplicate webhook fires, the recorder finds an existing transaction with the same reference and returns it with `wasReplayed = true`. No second transaction, no double-counted money.

```php
$result = Ledger::post(new StripeChargeSucceededPosting(/* ... */));

if ($result->wasReplayed) {
    Log::info("Stripe event {$event->id} already processed — ignoring duplicate.");
}
```

## 7 — End-of-month platform fees (batch)

Charging a fee to every seller is one posting per seller. Do **not** try to compress thousands of sellers into a single transaction — a transaction models one economic event.

```php
$scope = Ledger::for('platform-main');
$feeRevenue = $scope->account('platform.revenue.commission.usd');
$period = '2026-05';

foreach ($sellers as $seller) {
    Ledger::post(new MonthlyFeePosting(
        sellerId: $seller->id,
        period: $period,
        sellerAvailable: $seller->account('available.usd'),
        feeRevenue: $feeRevenue,
        fee: Money::of(500, 'USD'),  // $5.00
    ));
}
```

Give `MonthlyFeePosting::reference()` a value built from both the seller and the period — `Reference::for('fee.monthly', $sellerId, $period)` — so re-running the batch is safe. If the job dies halfway and restarts, sellers already charged are skipped via idempotency.

## What to take away

- Resolve accounts **before** constructing a Posting. Pass them in.
- Decide debit vs credit by asking "is this account increasing or decreasing, and what is its type?"
- Every transaction must balance — the recorder rejects it otherwise.
- Reference scopes are your idempotency keys. Choose them so a safe retry produces the *same* reference.
- Full undo → `Ledger::reverse()`. Partial undo → a new Posting.
