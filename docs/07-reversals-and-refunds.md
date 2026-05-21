# Reversals vs Refunds

This is the single most-misunderstood distinction in the package. Get it wrong and your books drift. Read this page even if you think you already know.

## The rule

> **A reversal undoes a transaction that should never have happened.**
> **A refund is a new economic event that happens to partially undo an earlier one.**

Reversals and refunds *look* similar — both move money "back" — but they answer different questions and live on different rows.

## Reversal

Use when **the original transaction was a mistake**:

- A duplicate charge.
- A fraud chargeback.
- A typo that posted the wrong amount.

```php
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Transaction;

$original = Transaction::query()
    ->where('ledger_id', $ledger->id)
    ->where('reference', "order.paid:{$order->id}")
    ->firstOrFail();

$result = Ledger::reverse($original, reason: 'duplicate charge');

$result->transaction;   // the reversal transaction
$result->wasReplayed;   // true if this reversal was already recorded
```

`Ledger::reverse()` builds the compensating transaction for you. You do **not** write a Posting for it. The reversal:

- is a brand-new, immutable `Transaction`,
- has `reverses_transaction_id` pointing back at the original,
- contains one entry per original entry, each with the **opposite** direction and the **same** amount,
- is automatically balanced (inverting a balanced transaction yields a balanced transaction).

The original transaction is never mutated.

### Constraints (enforced, not advisory)

- **A transaction can be reversed at most once.** The database has a `UNIQUE` constraint on `reverses_transaction_id`. A second `Ledger::reverse()` on the same transaction throws `ReversalNotAllowedException`.
- **A reversal cannot itself be reversed.** `ReversalPosting` rejects this in its constructor — also `ReversalNotAllowedException`. If you need to re-apply the original effect, post a fresh operation.

### What the entries look like

Original `OrderPaidPosting`:

```
debit  platform.cash.usd                 10000
credit escrow.usd (seller A)              9000
credit platform.revenue.commission.usd    1000
```

Its reversal (built automatically):

```
credit platform.cash.usd                 10000
debit  escrow.usd (seller A)              9000
debit  platform.revenue.commission.usd    1000
```

After both exist, the net effect on every account is zero. The original is **not** marked "refunded" — its reversed state is *derived* by checking whether a transaction exists with `reverses_transaction_id = $original->id` (`$transaction->isReversed()`).

## Refund

Use when **the original transaction was correct, and a new transaction reverses part of its effect**:

- The buyer accepted delivery but later asks for a partial refund.
- A seller voluntarily refunds a small concession.
- A pro-rated cancellation mid-cycle.

A partial refund is **not** a reversal. It is a fresh `Posting` with its own reference and its own entries — possibly with different amounts and different accounts than the original. See [`06-postings-cookbook.md`](06-postings-cookbook.md) §5 for the full `PartialRefundPosting` example.

```php
Ledger::post(new PartialRefundPosting(
    refundId: $refund->id,
    sellerEscrow: $seller->account('escrow.usd'),
    commissionRevenue: $scope->account('platform.revenue.commission.usd'),
    platformCash: $scope->account('platform.cash.usd'),
    refundAmount: Money::of(3_000, 'USD'),
    commissionClaw: Money::of(300, 'USD'),
));
```

## Decision table

| Situation | Reversal? | Why |
| --- | --- | --- |
| Duplicate posting from a buggy webhook handler | ✅ Reverse | The original should not have happened. |
| Fraud chargeback initiated by the bank | ✅ Reverse | The original is being undone in full by an external authority. |
| Seller voluntarily refunds $5 of a $100 order | ❌ New Posting | The original was correct; this is a new event. |
| Buyer cancels mid-cycle, owed a pro-rated $30 of $50 | ❌ New Posting | The original was correct; this is a new event with new math. |
| Operator typed the wrong amount and noticed immediately | ✅ Reverse | The original should not have happened. |
| End-of-quarter rebate to high-volume sellers | ❌ New Posting | The original transactions were correct; the rebate is separate. |

## "Un-reversing": when a chargeback is overturned

A bank chargeback gets reversed in your ledger. Three weeks later the bank rules in your favour and the chargeback is overturned. You cannot "reverse the reversal" — that path is blocked.

The correct move: post a **new** operation that re-applies the original economic effect, with its own reference (e.g. `Reference::for('chargeback.overturned', $chargebackId)`). The ledger now contains three transactions — the original, its reversal, and the re-application — and the audit trail tells the whole story honestly.

## What happens if you confuse them

- **Treating a partial refund as a reversal** corrupts the audit trail. The original transaction stops being the truth of what happened on day one; it now claims to have been undone even though most of its value was earned and kept. You also can't reverse it again later if you genuinely need to.
- **Treating a full mistake as a partial refund** leaves the bad transaction on the books looking valid, with a separate corrective transaction beside it. The totals may still balance, but nobody reading the books can tell the original was an error.

When in doubt, ask one question: **was the original transaction correct at the moment it was posted?** If yes → refund (new Posting). If no → reverse.
