# Concepts

Seven concepts make up the entire package. Resist adding more.

## Ledger

A **bounded financial context**: one company, one product line, one wallet system. Every Account, Transaction, and Entry belongs to exactly one Ledger. No financial relationship crosses ledger boundaries; if you need to model a transfer between two ledgers, model it as two postings linked by `correlation_id`.

Identified by a stable `slug` (`platform-main`, `marketplace-eu`). The slug never changes.

## Account

A **bucket inside a ledger**. Has:

- a `type` (Asset, Liability, Equity, Revenue, Expense),
- a derived `normal_balance` (Debit for Asset/Expense, Credit for everything else),
- an immutable `currency`,
- an optional polymorphic `owner` (the user, order, or other model the account belongs to).

Accounts are identified by a stable, dotted, lowercase `code` (`platform.cash.usd`, `available.usd`). Codes are unique within a ledger.

Accounts have a binary lifecycle: **open** → **archived**. Archived accounts keep their history but refuse new entries (reversals exempted).

## Transaction

One **atomic, balanced journal entry**. A single business event. The smallest unit the package records.

Has:

- a unique `reference` (the idempotency key),
- a `currency` shared by every line,
- a `posting_type` (the class that produced it),
- `posted_at` (business time, caller-supplied) and `recorded_at` (system time),
- optionally, `reverses_transaction_id` (when this transaction reverses another),
- optionally, `correlation_id` (when this transaction is part of a larger linked operation).

Once recorded, **no column on a transaction is ever mutated**. Reversal status is derived by looking for another transaction that reverses this one; it is not stored on the original.

## Entry

One debit or credit on one account. Multiple Entries make up a Transaction.

Has:

- `direction` (debit or credit),
- `amount` (always positive — direction encodes sign, not the value),
- `currency` (must match the account's currency),
- `posted_at` (copied from the transaction for partitioning).

Entries are the **source of truth**. Balances are derived. Anything you can compute from entries is correct; anything you can only get from a projection is approximate.

## Posting

A **domain operation class** that produces a `TransactionDraft`. The only public way to write to the ledger.

```php
final class OrderPaidPosting extends Posting
{
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

The caller resolves the accounts and constructs the Posting:

```php
$scope = Ledger::for('platform-main');

Ledger::post(new OrderPaidPosting(
    orderId: $order->id,
    cash: $scope->account('platform.cash.usd'),
    revenue: $scope->account('platform.revenue.usd'),
    total: Money::of(9_900, 'USD'),
));
```

**Two rules**:

1. **Deterministic.** Same constructor inputs → same `reference()` and same `entries()`, every time.
2. **No I/O inside `entries()`.** No DB queries, no HTTP, no cache reads. Resolve accounts and compute every Money *before* constructing the Posting, and pass them in.

Violating either of these silently corrupts the ledger on deadlock retry. See [`04-the-posting-contract.md`](04-the-posting-contract.md).

## Money

Integer minor units + ISO 4217 currency. The only monetary type the package accepts.

```php
Money::of(1_999, 'USD');   // $19.99
Money::zero('EUR');
Money::of(100, 'USD')->plus(Money::of(50, 'USD'));   // $1.50
Money::of(100, 'USD')->plus(Money::of(50, 'EUR'));   // throws
```

Floats never cross the `Money` boundary. Amounts are non-negative; direction lives on the Entry, not the value.

## Reference

The **idempotency key** for a transaction. Always dot-scoped:

```php
Reference::for('order.paid', $order->id);       // order.paid:42
Reference::for('stripe.event', $event->id);     // stripe.event:evt_abc
Reference::for('payout.settled', $sellerId, $i); // payout.settled:7:3
```

The scope is mandatory and must contain a dot. This is the only thing preventing two unrelated business operations from accidentally minting the same key.

---

## What's deliberately missing

You will not find **Balance** in the concept list. The `balances` table exists, but it is a *projection cache* of derived state, not part of the domain language. Anything that cares about correctness should ultimately consult Entries.

You will not find **Reversal** in the concept list either. A reversal is a `Posting` subclass (`ReversalPosting`). Treating it as a peer of Transaction invites people to look for a `reversals` table that does not exist.
