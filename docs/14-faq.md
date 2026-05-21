# FAQ

Common questions, answered against the real implementation.

### How do I get an account if there's no `Account::byCode()`?

Resolve it explicitly through the ledger scope or an owner model:

```php
Ledger::for('platform-main')->account('platform.cash.usd');  // by ledger + code
$user->account('available.usd');                             // an owner's account
```

There is deliberately no static `Account::byCode()`. A static lookup hides a database query, and a Posting must never query inside `entries()` (it breaks determinism on retry). Resolve accounts in your application code, then pass the resolved `Account` objects into your Posting's constructor. See [`04-the-posting-contract.md`](04-the-posting-contract.md).

### Why can't I just update a transaction or insert an entry directly?

The ledger is append-only and every write must be balanced, validated, and idempotent. The `TransactionRecorder` is the only code allowed to write financial models; it enforces all of that. A direct `save()`/`update()`/`delete()` on `Transaction`, `Entry`, `Account`, `Ledger`, or `Balance` throws `DirectWriteForbiddenException`. Route every change through `Ledger::post()` or `Ledger::reverse()`.

### What's the difference between `balance()` and `balanceAsOf()`?

`balance()` reads the cached projection — O(1), the balance right now. `balanceAsOf($moment)` aggregates the entries table — slower, but authoritative, and it can answer "what was the balance at a past date". They agree for `balanceAsOf(now())`. Full detail in [`08-balances.md`](08-balances.md).

### `balance()` returned a negative number. Is that a bug?

Not necessarily. `balance()` returns a *signed* integer. A negative value means the account is underwater relative to its normal side — e.g. a cash account paid out beyond what came in. Whether that is valid is a question for your application. If you must prevent it, add a custom validator ([`10-extensions.md`](10-extensions.md)). `balanceMoney()` throws on a negative balance because `Money` cannot be negative; use `balance()` when an account may legitimately go below zero.

### Posting the same thing twice — does it double the money?

No. Posting is idempotent on the `Reference`. The second post returns the original transaction with `wasReplayed = true` and writes nothing. This is what makes webhook handlers safe — give the Posting a reference built from the external event id.

### How do I do a partial refund?

Post a new Posting describing the refund. A partial refund is a *new economic event*, not an undo. Only a full reversal of a mistaken transaction uses `Ledger::reverse()`. See [`07-reversals-and-refunds.md`](07-reversals-and-refunds.md).

### Can I reverse a transaction twice, or reverse a reversal?

No, both are blocked. A transaction can be reversed at most once (a `UNIQUE` database constraint). A reversal cannot itself be reversed (rejected in `ReversalPosting`'s constructor). Both throw `ReversalNotAllowedException`. If a chargeback is later overturned, post a *new* transaction re-applying the original effect — see the "un-reversing" section in [`07-reversals-and-refunds.md`](07-reversals-and-refunds.md).

### How do I handle two currencies in one operation?

You don't — a transaction is single-currency by invariant. Model a currency exchange as **two** postings, one per currency, linked by a shared `correlationId()`. The cross-currency rate math lives in your application (or a future FX companion package), never inside one mixed transaction.

### Do I need one ledger or several?

Most applications need one. Use multiple ledgers only for genuinely separate financial contexts (e.g. distinct legal entities). Entries never cross a ledger boundary — that is an enforced invariant. Related transactions in different ledgers are linked by `correlationId()`, not by shared entries.

### Where does the `normal_balance` come from?

It is derived automatically from the account `type` — Assets and Expenses are debit-normal, Liabilities/Equity/Revenue are credit-normal. It is a generated database column; you never set it. See [ADR 0004](adr/0004-generated-normal-balance.md).

### Can a listener post another transaction?

Not synchronously. The recorder's transaction has already committed when an event fires. If a `TransactionPosted` listener must trigger a follow-up posting, dispatch a queued job and let a worker call `Ledger::post()`. See [`11-events-and-exceptions.md`](11-events-and-exceptions.md).

### How do I import balances from a legacy system?

Write an opening-balance Posting that books each legacy balance against an `equity.opening-balance` account. Full worked example in [`09-operations.md`](09-operations.md). Never insert into the `balances` table directly.

### `ledger:verify` reports drift. What now?

If the *entries* are correct but the *projection* is wrong, run `ledger:rebuild-balances` — it recomputes the projection from the authoritative entries. If a *zero-sum* check fails, that is far more serious (the entries themselves are inconsistent): stop processing and investigate before rebuilding anything. See [`09-operations.md`](09-operations.md).

### Does the package support soft deletes?

No, and it never will. Deletion of any financial record is forbidden — `delete()` on a financial model throws. Correcting history is done by posting compensating transactions, not by deleting rows. See [`12-anti-features.md`](12-anti-features.md).

### Can I add invoices / a UI / tax / FX?

Not in the core. Those are application concerns or companion packages. The core does one thing: post immutable, balanced, idempotent, double-entry transactions and read them back accurately. See [`12-anti-features.md`](12-anti-features.md) for the full list and the reasoning.
