# Changelog

All notable changes to `laravel-ledger` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

A production-hardening pass focused on correctness, concurrency, and operational scalability. The public API is unchanged in shape; existing code keeps working.

### Added

- **`Ledger::postMany(iterable<Posting>)`** — batch posting API that records every Posting in a single DB transaction. Atomic per batch; per-Posting idempotency preserved.
- **`Posting::type()`** — stable, refactor-proof token persisted in `transactions.posting_type` (defaults to FQCN for backward compatibility). `ReversalPosting` reports `ledger.reversal`.
- **`Account::scopeWithBalance()`** — eager-loads the balance projection to eliminate the `balance()`-in-a-loop N+1.
- **`LedgerWriteFailedException`** — wraps terminal `QueryException`s after retry exhaustion so `catch (LedgerException)` covers every package-level write failure.
- **`PostedAtBoundsValidator`** (required) — rejects postings beyond `ledger.max_clock_skew_seconds` in the future (default 300s) or before an optional `ledger.historical_lower_bound`. Closes the silent-corruption channel on `balanceAsOf` queries.
- **`IdempotencyMatch` DTO** — lightweight return type from `IdempotencyStore::find()`, replacing the full Eloquent `Transaction` so non-DB implementations can satisfy the contract honestly.
- **`accounts.archived_at` and `accounts.archived_by`** columns — captured by `archiveAccount(Account $a, ?string $actor = null)`; surfaces in `AccountArchived` event payload.
- **CHECK constraint parity** on MySQL/MariaDB for amount > 0, valid direction, valid account type, and currency format (previously Postgres-only). A backfill migration brings existing installs on Postgres/MySQL up to parity.
- **MySQL 8 and Postgres 16 CI matrix** in addition to the existing SQLite matrix.

### Changed

- **`ledger:verify` and `ledger:rebuild-balances`** — rewritten as set-based SQL (GROUP BY aggregations / `INSERT … SELECT`). Query count is now O(1) per ledger instead of O(N) per transaction/account. Behaviour and exit codes unchanged.
- **`WritableOnlyByRecorder`** — replaced the per-class static depth counter with a `WeakMap` keyed by the current Fiber. Concurrent coroutines (Swoole, RoadRunner, `Octane::concurrently()`) now have isolated windows.
- **`TransactionRecorder`** — `max_attempts` is now configurable via `ledger.recorder.max_attempts`. Original transaction for `TransactionReversed` is captured before `afterCommit()` to avoid a post-write requery.
- **`DatabaseBalanceProjector`** — projection upsert uses bound parameters instead of raw `Expression` interpolation.
- **`Reference::for()`** — rejects parts containing a colon to prevent silent idempotency collisions between `('order.paid', '1:2')` and `('order.paid', '1', '2')`.
- **`BalanceProjector`** contract — docblock now explicitly requires synchronous, in-transaction application. Async projection remains unsupported (write a companion package).
- **`Posting` stub and docs** — guide authors to declare a stable `type()` token aligned with their Reference scope.
- **Validators (`AccountCurrencyMatch`, `AccountState`, `LedgerScope`)** — stop re-checking the recorder's account-presence precondition; behaviour unchanged because the recorder already throws `AccountNotFoundException` first.

### Documentation

- New: Octane/Swoole/RoadRunner safety section in [Operations](docs/09-operations.md).
- New: Batch posting recipe.
- New: Idempotency retention semantics (references are permanent).
- New: 64-bit PHP requirement and bigint scaling.
- Updated: Testing guide spells out `Carbon::setTestNow()` for `SystemClock`.
- Updated: Events guide requires `ShouldQueue` for non-trivial listeners.
- Updated: Invariants table includes the new `posted_at` validator.
- Updated: Period-close anti-feature explains the minimum-viable pattern.

### Upgrading

See [UPGRADING.md](UPGRADING.md). The migration story is:

1. Run `php artisan migrate` to pick up the new migrations (`add_check_constraint_parity` and `add_archived_audit_columns`).
2. Catch `LedgerException` instead of `QueryException` in code that handled deadlock or constraint-violation failures from `Ledger::post()`.
3. Optional: override `Posting::type()` on existing Postings to switch from FQCN to stable tokens.
4. If you bind a custom `IdempotencyStore`, update it to return `?IdempotencyMatch` instead of `?Transaction`.

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
