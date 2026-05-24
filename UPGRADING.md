# Upgrading

Versioned upgrade notes for `syriable/laravel-ledger`. Read the section for the version you are jumping *to*.

## Index

- [`0.9.x` → `1.0.0-rc.1`](#09x--100-rc1)

---

## `0.9.x` → `1.0.0-rc.1`

`1.0.0-rc.1` is the first release candidate. It is a **production-hardening release** — the three-verb public API is preserved, and most installs need only `php artisan migrate`. The full set of changes is in [CHANGELOG.md](CHANGELOG.md#100-rc1---2026-05-24).

### Estimated upgrade time

- **5 minutes** for the required steps (1 and 2).
- **+5 minutes** for each optional step you choose to adopt (3–6).

### TL;DR

```bash
composer require syriable/laravel-ledger:^1.0.0-rc.1
php artisan migrate
```

Then audit any code that catches `QueryException` from `Ledger::post()` and switch the catch type to `LedgerException`.

---

### 1. Required — Run the new migrations

Two new migrations ship with this release. Both are idempotent (safe to re-run) and additive (no destructive changes).

```bash
php artisan migrate
```

| Migration | Effect | Safe for live data |
| --- | --- | --- |
| `2026_01_02_000001_add_check_constraint_parity` | Adds the MySQL/Postgres CHECK constraints (`amount > 0`, valid `direction`, currency-format regex, valid account `type`) that previously existed only on Postgres. Skips constraints already present. | Yes — fails loudly only if existing data already violates an invariant, which means you have drift to fix. Run `php artisan ledger:verify` first if you want a dry-run signal. |
| `2026_01_02_000002_add_archived_audit_columns` | Adds nullable `archived_at` and `archived_by` columns to `accounts`. | Yes — both columns are nullable; prior archives become rows with NULL audit fields. |

**SQLite users:** the parity migration is a no-op on SQLite (which does not support `ALTER TABLE ADD CONSTRAINT`). PHP-level Money / Validator enforcement continues to cover these invariants on SQLite. This is documented in [`docs/03-invariants.md`](docs/03-invariants.md).

### 2. Required — Catch `LedgerException` instead of `QueryException`

`TransactionRecorder` now wraps terminal `QueryException`s in `LedgerWriteFailedException` (a subclass of `LedgerException`). Code that previously caught `QueryException` from `Ledger::post()` should switch to:

```diff
-try {
-    Ledger::post($posting);
-} catch (\Illuminate\Database\QueryException $e) {
-    // …
-}
+try {
+    Ledger::post($posting);
+} catch (\Syriable\Ledger\Exceptions\LedgerException $e) {
+    // every package-level failure (validation, retry exhaustion, terminal DB error)
+}
```

If you specifically need to distinguish deadlock-budget exhaustion, catch `LedgerWriteFailedException` and inspect `$e->getPrevious()`.

---

### 3. Optional — Stable `posting_type` tokens

`Posting::type()` defaults to `static::class` so historical rows keep working. To migrate a Posting away from its FQCN:

```php
public function type(): string
{
    return 'order.paid';  // pick once; never change
}
```

Old rows keep their FQCN; new rows use the stable token. If you want to backfill old rows to the new token, do it explicitly in a one-off migration:

```php
DB::table('transactions')
    ->where('posting_type', 'App\\Ledger\\Postings\\OrderPaidPosting')
    ->update(['posting_type' => 'order.paid']);
```

### 4. Optional — Tighten the new `posted_at` bounds

`PostedAtBoundsValidator` is in the required set with a 300-second future-skew window and **no** historical floor. The defaults are intentionally generous so existing callers are not surprised. If your application benefits from stricter bounds:

```php
// config/ledger.php
'max_clock_skew_seconds' => 0, // forbid any future-dated postings
'historical_lower_bound' => '2020-01-01T00:00:00Z',
```

Bounds apply only to **new postings**; existing rows are not re-validated.

### 5. Optional but recommended — Make listeners `ShouldQueue`

`TransactionPosted` and `TransactionReversed` fire via `DB::afterCommit()` and run synchronously. A listener that throws — for any reason — propagates back through `Ledger::post()` **after** the financial write has succeeded. Callers typically interpret that as a write failure and retry; the retry hits idempotency (`wasReplayed = true`) and silently buries the listener bug.

The safe default is to make non-trivial listeners queued:

```php
final class ReindexAfterPosting implements ShouldQueue
{
    public function handle(TransactionPosted $event): void { /* … */ }
}
```

See [Events & Exceptions — Listeners must implement `ShouldQueue`](docs/11-events-and-exceptions.md#listeners-must-implement-shouldqueue).

### 6. Optional — Adopt the new `withBalance` scope to kill latent N+1s

Anywhere your code iterates accounts and calls `balance()`:

```diff
-$accounts = Account::query()->where('ledger_id', $id)->get();
-$accounts->each(fn ($a) => $a->balance());          // N+1: one query per account
+$accounts = Account::query()->withBalance()->where('ledger_id', $id)->get();
+$accounts->each(fn ($a) => $a->balance());          // zero extra queries
```

### 7. Optional — Use `Ledger::postMany()` for bulk writes

`postMany(iterable<Posting>)` records every Posting in a single DB transaction:

```php
Ledger::postMany([
    new OrderPaidPosting(...),
    new OrderPaidPosting(...),
    new OrderPaidPosting(...),
]);
```

Atomic per batch; per-Posting idempotency preserved. Significant throughput win for legacy imports and high-volume workers.

---

### Custom `IdempotencyStore` implementations

If you ship your own `IdempotencyStore`, the contract now returns `?IdempotencyMatch` (a small DTO with a `transactionId`) instead of `?Transaction`:

```diff
-public function find(string $ledgerId, Reference $reference): ?Transaction
+public function find(string $ledgerId, Reference $reference): ?IdempotencyMatch
{
    $id = /* your lookup that returns just the transaction id */;

    return $id === null ? null : new IdempotencyMatch($id);
}
```

The default `DatabaseIdempotencyStore` is updated; no action needed if you use it.

### Octane / Swoole / RoadRunner

No code change required. The recorder-window safety net (`WritableOnlyByRecorder`) is now Fiber-aware via a `WeakMap`, so concurrent coroutines no longer share each other's open windows. See [Operations — Running under Octane / Swoole / RoadRunner](docs/09-operations.md#running-under-octane--swoole--roadrunner).

### Behavioural changes worth knowing

- **`Reference::for()` now rejects parts that contain a `:` character.** Prior callers must either split into multiple positional parts (`Reference::for('order.paid', '1', '2')`) or use `Reference::fromString()` for the legacy form.
- **`ledger:verify` and `ledger:rebuild-balances` now run as set-based SQL.** Output format and exit codes unchanged; runtime drops from O(N) queries to O(1) per ledger.
- **`TransactionReversed`** is dispatched with the original transaction resolved at write time rather than via a post-commit requery. No API change; one fewer round-trip.
