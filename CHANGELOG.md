# Changelog

All notable changes to `laravel-ledger` will be documented in this file.

## Unreleased# Changelog

All notable changes to `laravel-ledger` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## 0.9.0 - 2026-05-21

First public release candidate. The API is stabilizing but not yet frozen;
breaking changes may still occur in `0.x` minor releases before `1.0.0`.

### Added

- Immutable, append-only, double-entry ledger engine.
- Core domain models: `Ledger`, `Account`, `Transaction`, `Entry`, `Balance`.
- Value objects: `Money` (integer minor units), `Reference` (dot-scoped
  idempotency key), `AccountCode`.
- Three-verb public API via the `Ledger` facade: `openAccount()`, `post()`,
  `reverse()`, plus `createLedger()`, `for()` and `archiveAccount()`.
- `Posting` abstract base class — the single, deterministic, I/O-free entry
  point for all writes — and `ReversalPosting` for compensating transactions.
- `TransactionRecorder` — the only writer; atomic, idempotent, with ordered
  pessimistic locking and bounded deadlock retries.
- Seven required transaction validators plus an append-only, extensible
  validator pipeline.
- Synchronous balances projection with a swappable `BalanceProjector` contract.
- Swappable `IdempotencyStore` contract (default reads `transactions.reference`).
- `Clock` contract for deterministic time in tests.
- `HasAccounts` trait for owner models (e.g. `User`, `Order`).
- Events: `TransactionPosted`, `TransactionReversed`, `AccountOpened`,
  `AccountArchived`, all dispatched after commit.
- `WritableOnlyByRecorder` safeguard — direct writes to financial models throw
  `DirectWriteForbiddenException`.
- Artisan commands: `make:posting`, `ledger:verify`, `ledger:rebuild-balances`.
- Database migrations for PostgreSQL, MySQL 8+, and SQLite, with generated
  `normal_balance` column and a unique constraint enforcing once-only reversal.
- Full documentation set (14 guides + 5 architecture decision records).

### Requirements

- PHP 8.3+
- Laravel 11 or 12

### Added

- Initial release: immutable, append-only, double-entry ledger engine.
- Core domain: `Ledger`, `Account`, `Transaction`, `Entry`, `Posting`, `Money`, `Reference`.
- `Ledger` facade with three verbs — `openAccount`, `post`, `reverse` — plus `createLedger`, `for`, and `archiveAccount`.
- `TransactionRecorder` — atomic, idempotent, deadlock-retrying writer with after-commit event dispatch.
- Seven required validators behind an extensible, required-first pipeline.
- Synchronous `BalanceProjector` with a swappable contract; `IdempotencyStore` contract.
- `WritableOnlyByRecorder` trait — financial models reject writes outside the recorder.
- `HasAccounts` trait for owner models.
- Artisan commands: `make:posting`, `ledger:verify`, `ledger:rebuild-balances`.
- Documentation set including a postings cookbook, reversal-vs-refund guide, and architecture decision records.
