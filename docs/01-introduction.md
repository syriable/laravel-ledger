# Introduction

This package exists because every Laravel app that handles money eventually invents its own ledger — badly. The first version lives in `transactions.balance_after`. The second version lives in `wallets.balance`. By the third version someone has noticed that the totals don't reconcile and spent a weekend writing a script to recompute them, and nobody can explain what happened on the third Tuesday of last March.

Laravel Ledger replaces all of that with a small, opinionated engine built on two principles that bookkeepers have used for 500 years.

## Principle 1 — every transaction is balanced

Every business event is recorded as two or more entries whose debits equal their credits. Cash leaves one account; it must arrive in another. Nothing is created from nothing.

```
cash decreases  ──── (credit) ──── platform.cash.usd        $100
revenue increases ── (credit) ──── platform.revenue.usd     $100
                                                            ─────
                                                  Σ debits  $100
                                                  Σ credits $100
```

This is **double-entry accounting**. The package refuses to record anything that violates it.

## Principle 2 — nothing is ever rewritten

A recorded transaction is immutable. If it was wrong, the correction is a new transaction that compensates for it. The original stays on the books forever.

This sounds restrictive until you've spent a week trying to figure out what changed between two month-end reports. Then it sounds like freedom.

## What the package provides

A small core with seven concepts and three verbs.

### Concepts

- **Ledger** — a book of accounts. A bounded financial context.
- **Account** — a bucket inside a ledger.
- **Transaction** — one atomic, balanced journal entry.
- **Entry** — one debit or credit line inside a transaction.
- **Posting** — the domain operation class that produces a transaction.
- **Money** — the only allowed monetary type (integer minor units + currency).
- **Reference** — the idempotency key for a transaction.

### Verbs

```php
Ledger::openAccount(/* ... */);     // open
Ledger::post($posting);             // post
Ledger::reverse($transaction);      // reverse
```

That is the entire write surface.

## What the package will never provide

See [12-anti-features.md](12-anti-features.md). The short version: invoices, PDFs, UI, tax engines, FX engines, wallets, and REST APIs are out of scope, forever, in the core. Some of them ship as companion packages.
