# The Posting Contract & Direction of Value

This is the most important page in the documentation. If you have never done double-entry bookkeeping, read it slowly and completely. Everything else in the package depends on you getting this right.

## Part 1 — Debit and credit, with no jargon

Forget every association you have with the words "debit" and "credit" from your bank statements. In double-entry accounting they are not "money out" and "money in". They are just the **two sides of an account**: left and right.

- **Debit** = the left side.
- **Credit** = the right side.

That's it. A debit is not inherently good or bad, not inherently an increase or decrease. What a debit *does* depends entirely on the **type** of account it touches.

### The five account types

Every account has one of five types:

| Type | Examples |
| --- | --- |
| **Asset** | cash, bank balance, money held on behalf of others |
| **Liability** | money you owe — seller balances, escrow, customer wallet balances |
| **Equity** | the owners' stake, retained earnings, opening balances |
| **Revenue** | income — commissions, fees earned |
| **Expense** | costs — processing fees paid, refunds-as-cost |

### Normal balance: which side increases the account

Each type has a **normal balance** — the side on which a positive amount *increases* it:

| Account type | Normal balance | Debit does… | Credit does… |
| --- | --- | --- | --- |
| Asset | **Debit** | increases ↑ | decreases ↓ |
| Expense | **Debit** | increases ↑ | decreases ↓ |
| Liability | **Credit** | decreases ↓ | increases ↑ |
| Equity | **Credit** | decreases ↓ | increases ↑ |
| Revenue | **Credit** | decreases ↓ | increases ↑ |

You do not have to memorise this table cell by cell. Memorise one sentence:

> **Debit increases Assets and Expenses. Credit increases everything else.**

The opposite direction always *decreases*. That single rule generates the whole table.

In this package, normal balance is **derived from the type automatically** — you set `AccountType::Asset` and the `normal_balance` column becomes `debit` on its own. You never set it by hand.

### Why amounts are always positive

In this package a `Money` value is always **≥ 0**. There are no negative amounts. Direction — whether value flows in or out of an account — is carried entirely by whether the entry is a debit or a credit.

This is deliberate. "Negative money" is ambiguous: negative relative to what? Encoding direction in the debit/credit choice removes the ambiguity. An entry says *which account, which side, how much* — and the account's type tells you whether that raised or lowered the balance.

### The iron law: every transaction balances

In a single transaction, **the sum of all debit amounts must equal the sum of all credit amounts.** This is double-entry. Value is never created or destroyed; it only moves. If $100 leaves one place, $100 must arrive somewhere else.

The recorder enforces this. An unbalanced draft is rejected with `ImbalancedTransactionException` — it never touches the database.

## Part 2 — Working an example by hand

A buyer pays $100 for an order. The seller's cut is $90; the platform keeps $10 commission.

Three accounts are involved:

- `platform.cash.usd` — **Asset**. Real money arrives here.
- `escrow.usd` (the seller's) — **Liability**. We now owe the seller their $90.
- `platform.revenue.commission.usd` — **Revenue**. We earned $10.

Now decide each entry by asking *"is this account going up or down, and what type is it?"*

1. **Cash goes up by $100.** Cash is an Asset. Debit increases an Asset → **debit cash $100**.
2. **We owe the seller $90 more.** Escrow is a Liability. Credit increases a Liability → **credit escrow $90**.
3. **We earned $10.** Commission is Revenue. Credit increases Revenue → **credit commission $10**.

Check the iron law: debits = 100. Credits = 90 + 10 = 100. **Balanced.** This transaction is valid.

In code, that is exactly what `OrderPaidPosting::entries()` returns:

```php
public function entries(): array
{
    return [
        EntryDraft::debit($this->cash, $this->total),                  // 10000
        EntryDraft::credit($this->sellerEscrow, $this->sellerNet),      // 9000
        EntryDraft::credit($this->commissionRevenue, $this->commission),// 1000
    ];
}
```

`EntryDraft::debit($account, $money)` and `EntryDraft::credit($account, $money)` are the only two ways to build an entry. The first argument is the account; the second is a positive `Money`.

### A second example: moving money you already hold

The order completes. The seller's $90 moves from escrow ("held") to available ("withdrawable"). **Both** accounts are Liabilities.

1. **Escrow goes down by $90.** Liability. Debit decreases a Liability → **debit escrow $90**.
2. **Available goes up by $90.** Liability. Credit increases a Liability → **credit available $90**.

Debits = 90. Credits = 90. Balanced.

Notice: no Asset moved. No real cash changed hands. This transaction just reclassifies an existing obligation. That is completely normal — most ledger activity is moving value between accounts you already control.

## Part 3 — The Posting class

A `Posting` is a class that describes one business operation and produces one balanced transaction. It is the **only** way to write to the ledger. There is no "insert a transaction directly" API, by design.

### The five methods you implement

```php
use Syriable\Ledger\Postings\Posting;

final class OrderPaidPosting extends Posting
{
    public function ledger(): string;        // required — the ledger slug
    public function currency(): string;      // required — ISO 4217, e.g. 'USD'
    public function reference(): Reference;  // required — the idempotency key
    public function entries(): array;        // required — list<EntryDraft>

    public function description(): ?string;  // optional — human-readable note
    public function correlationId(): ?string;// optional — links related postings
    public function metadata(): array;       // optional — audit-only key/value data
    public function postedAt(Clock $clock): CarbonImmutable; // optional — business time
}
```

The four `abstract` methods (`ledger`, `currency`, `reference`, `entries`) must be implemented. The rest have sensible defaults — override only what you need.

### The contract — three rules you must not break

**Rule 1 — A Posting must be deterministic.**

Given the same constructor inputs, `reference()` and `entries()` must return the same result every single time they are called. The recorder may call them more than once (a database deadlock triggers an automatic retry). If the second call produces different entries, the ledger is silently corrupted.

**Rule 2 — A Posting must not perform I/O inside `entries()` (or `reference()`).**

No database queries. No HTTP calls. No cache reads. No `Account::query()`. The reason follows directly from Rule 1: anything you query can change between calls, which destroys determinism.

This is why every example resolves accounts *outside* the Posting and passes them into the constructor:

```php
// CORRECT — caller resolves accounts, passes them in
$scope = Ledger::for('platform-main');

Ledger::post(new OrderPaidPosting(
    orderId: $order->id,
    cash: $scope->account('platform.cash.usd'),       // resolved here
    sellerEscrow: $seller->account('escrow.usd'),     // resolved here
    commissionRevenue: $scope->account('platform.revenue.commission.usd'),
    total: Money::of(10_000, 'USD'),                  // computed here
    sellerNet: Money::of(9_000, 'USD'),
    commission: Money::of(1_000, 'USD'),
));
```

```php
// WRONG — querying inside entries() breaks determinism
public function entries(): array
{
    $cash = Account::query()->where('code', 'platform.cash.usd')->first(); // ❌ I/O
    // ...
}
```

There is no `Account::byCode()` helper in this package, and there never will be — a static lookup is exactly the hidden query this rule forbids. Resolve accounts explicitly with `Ledger::for($slug)->account($code)` or `$owner->account($code)`, in your application code, before constructing the Posting.

**Rule 3 — Compute money before construction.**

All amounts must be finished `Money` values by the time the Posting is constructed. Do not compute fees, tax, or splits inside `entries()` — compute them in your service layer, pass the results in. `entries()` should be pure assembly: take the values it was given and arrange them into debits and credits.

### Why the contract exists

The recorder runs each posting inside a database transaction with up to three automatic retries on deadlock. Retry means `entries()` runs again. The idempotency reference, however, is fixed after the first attempt. So if attempt 1 and attempt 2 of `entries()` disagree — because something they queried changed — the recorder commits attempt 2's numbers under attempt 1's reference. The books now disagree with reality, silently, with no error.

A deterministic, I/O-free Posting cannot have this bug. The contract is not bureaucracy; it is the property that makes retries safe.

## Part 4 — Generating a Posting

```bash
php artisan make:posting OrderPaidPosting
```

This creates `app/Ledger/Postings/OrderPaidPosting.php` from a stub that already encodes the contract — constructor-injected dependencies, no I/O, a dot-scoped reference. Fill in the blanks.

## Checklist before you post

Before calling `Ledger::post()`, confirm:

- [ ] Every account the Posting needs was resolved by the caller and passed into the constructor.
- [ ] Every `Money` amount was computed before construction.
- [ ] `entries()` only assembles — it does not query or compute.
- [ ] The debits and credits sum to equal totals.
- [ ] Each entry's direction is correct for its account type (use the table in Part 1).
- [ ] `reference()` is dot-scoped and stable — a safe retry produces the *same* reference.
- [ ] `currency()` matches the currency of every account and every `Money` in the posting.

If all seven hold, the posting is correct and the recorder will accept it.
