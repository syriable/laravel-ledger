# Documentation

Complete documentation for `syriable/laravel-ledger` — an immutable, append-only, double-entry financial ledger engine for Laravel.

If you are new, read the pages in order. If you have used double-entry ledgers before, you can jump straight to the Posting contract and the cookbook.

## Start here

| # | Page | What it covers |
| --- | --- | --- |
| 01 | [Introduction](01-introduction.md) | Why this package exists and the problem it solves. |
| 02 | [Concepts](02-concepts.md) | The seven concepts the package is built from. |
| 03 | [Invariants](03-invariants.md) | The financial rules, and exactly what enforces each one. |

## Building with the package

| # | Page | What it covers |
| --- | --- | --- |
| 04 | [The Posting Contract & Direction of Value](04-the-posting-contract.md) | **The most important page.** Debit/credit from scratch, normal balances, the determinism rules. Read this before writing any Posting. |
| 05 | [Installation & Quickstart](05-installation-and-quickstart.md) | Install, configure, and go through the full lifecycle in five minutes. |
| 06 | [Postings Cookbook](06-postings-cookbook.md) | A complete marketplace lifecycle — payments, escrow, payouts, refunds, webhooks — worked end to end. |
| 07 | [Reversals vs Refunds](07-reversals-and-refunds.md) | The distinction that causes the most bugs, made explicit. |
| 08 | [Balances](08-balances.md) | Projection vs aggregation, signed balances, overdrafts, `balance()` vs `balanceAsOf()`. |

## Operating & extending

| # | Page | What it covers |
| --- | --- | --- |
| 09 | [Operations](09-operations.md) | `ledger:verify`, `ledger:rebuild-balances`, drift recovery, backups, legacy imports. |
| 10 | [Extension Points](10-extensions.md) | The five ways to extend the package — and what is intentionally closed. |
| 11 | [Events & Exceptions](11-events-and-exceptions.md) | Every event and every exception, with when each fires. |
| 12 | [Anti-features](12-anti-features.md) | What this package will never do, and why. |
| 13 | [Testing](13-testing.md) | How the package is tested, and how to test a system built on it. |
| 14 | [FAQ](14-faq.md) | Quick answers to common questions. |

## Architecture Decision Records

The reasoning behind the choices that are expensive to reverse:

- [ADR 0001 — Projection strategy](adr/0001-projection-strategy.md)
- [ADR 0002 — No `reversed_by_transaction_id` back-link](adr/0002-no-reversed-by-backlink.md)
- [ADR 0003 — No separate `idempotency_keys` table](adr/0003-no-idempotency-keys-table.md)
- [ADR 0004 — Generated `normal_balance` column](adr/0004-generated-normal-balance.md)
- [ADR 0005 — `correlation_id` and `tenant_id` columns shipped from v0.1](adr/0005-correlation-and-tenant-columns.md)

## The shortest possible summary

The package does one thing: **post immutable, balanced, idempotent, double-entry transactions safely, and read them back accurately.**

- You write a `Posting` class per business operation. It is deterministic and never queries the database.
- `Ledger::post()` validates it, writes it atomically, and updates balances — all in one database transaction.
- Nothing is ever mutated or deleted. Corrections are new, compensating transactions.
- `Ledger::reverse()` undoes a mistake. A new Posting handles a partial refund.
- `ledger:verify` proves, at any time, that the books are internally consistent.

Everything else in this documentation is detail in service of those five sentences.
