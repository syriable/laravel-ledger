# Anti-Features

Things this package will never do. Each item below has been or will be requested. The answer is "no, in the core."

## Invoicing, PDFs, exports

These are presentation concerns. The ledger holds the truth; an invoice is a formatted view of part of that truth. Build it in your app, or wait for a companion package.

## Tax engines

Tax rules are jurisdictional, mutable, and full of edge cases. They belong in a dedicated module — `laravel-ledger-tax`. The core stays generic.

What the core does provide for tax workflows: opening tax-specific accounts (`platform.tax.payable.usd`, etc.) and writing entries to them inside ordinary Postings. The *computation* of how much tax to charge is not core's problem.

## FX engines

Cross-currency value movement is two linked Postings joined by `correlation_id`. The core ships the `correlation_id` column and the contract; it does not ship the rate logic, the rate source, or the rounding policy. That's `laravel-ledger-fx`.

## Wallets

A "wallet" is a *view* over accounts plus policies (spending limits, KYC tiers, freeze rules). It is an app concern or a companion package — never core.

## UI

No Filament resources, no Nova resources, no Blade components. If a UI ships, it ships as `laravel-ledger-filament` in a separate repo.

## REST / GraphQL APIs

Not core's problem. Build the API your app needs in your app.

## Reporting (P&L, balance sheet, trial balance)

These are read-side projections over entries. They typically want read replicas, materialized views, and OLAP queries. Don't pollute the write-path schema with reporting concerns. Companion: `laravel-ledger-reporting`.

## Subscriptions, recurring billing

Use a recurring scheduler in your app that posts new Postings on cadence. The core doesn't model time-based recurrence.

## Period closing, fiscal year locks

Real requirement for any serious business. Will land as `laravel-ledger-closing`. Not core in v1.

## Multi-ledger transactions

The core supports *correlated* postings across ledgers via `correlation_id`. It does **not** support entries that span ledgers. One transaction, one ledger.

## Cross-database ledgers

One ledger lives in one database. If you need to shard, shard by ledger.

## Soft deletes

Forbidden everywhere. `WritableOnlyByRecorder::delete()` throws unconditionally.

## "Just one mutable field"

No. The boundary is binary: either a column is mutable or it isn't. The only mutable column on a financial model is `accounts.is_archived`, and that flag was a deliberate decision negotiated against the alternative of an `archived_accounts` table.

## "Can we let the user manually balance rounding errors?"

No. Use a dedicated `RoundingDifferencePosting` against an explicit `equity.rounding.usd` account. Rounding is a real economic event; treat it as one.

## "Can a listener post a follow-up transaction?"

Not directly inside the same request. Enqueue a job; let the worker call `Ledger::post()`. Listeners that write back to the ledger during the recorder's request are a recipe for partial-commit bugs.

## "Can the recorder accept raw arrays instead of Postings?"

No. The Posting is the type-safe, deterministic, idempotency-aware boundary. Arrays defeat all three.
