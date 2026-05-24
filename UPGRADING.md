# Upgrading

## From 0.9.0 → Unreleased

This is a production-hardening release. The public API is preserved; most upgrades require only `php artisan migrate`.

### 1. Run new migrations

Two new migrations ship with this release. Both are idempotent (safe to re-run) and additive (no destructive changes).

```bash
php artisan migrate
```

| Migration | Effect | Safe for live data |
| --- | --- | --- |
| `add_check_constraint_parity` | Adds MySQL/Postgres CHECK constraints (amount > 0, valid direction, valid currency format, valid account type) that previously existed only on Postgres. Skips constraints already present. | Yes — fails loudly only if existing data already violates an invariant (which means you have drift to fix). |
| `add_archived_audit_columns` | Adds nullable `archived_at` and `archived_by` columns to `accounts`. | Yes — both columns are nullable; prior archives become rows with NULL audit fields. |

SQLite users: the parity migration is a no-op on SQLite (which does not support `ALTER TABLE ADD CONSTRAINT`). PHP-level Validator enforcement continues to cover these invariants on SQLite.

### 2. Update exception handling

`TransactionRecorder` now wraps terminal `QueryException`s in `LedgerWriteFailedException` (a subclass of `LedgerException`). Code that previously caught `QueryException` from `Ledger::post()` should switch to:

```php
try {
    Ledger::post($posting);
} catch (\Syriable\Ledger\Exceptions\LedgerException $e) {
    // every package-level failure (validation, retry exhaustion, terminal DB error)
}
```

If you specifically need to distinguish deadlock-budget exhaustion, catch `LedgerWriteFailedException` and inspect `$e->getPrevious()`.

### 3. Custom `IdempotencyStore` implementations

The contract now returns `?IdempotencyMatch` (a small DTO with a `transactionId`) instead of `?Transaction`. If you have a custom implementation:

```diff
-public function find(string $ledgerId, Reference $reference): ?Transaction
+public function find(string $ledgerId, Reference $reference): ?IdempotencyMatch
{
    $id = /* your lookup that returns just the transaction id */;

    return $id === null ? null : new IdempotencyMatch($id);
}
```

The default `DatabaseIdempotencyStore` is updated; no action needed if you use it.

### 4. (Optional) Stable `posting_type` tokens

`Posting::type()` defaults to `static::class` so historical rows keep working. To migrate a Posting away from its FQCN:

```php
public function type(): string
{
    return 'order.paid';  // pick once; never change
}
```

Old rows keep their FQCN; new rows use the stable token. If you want to backfill the old rows to the new token, do it explicitly with `DB::table('transactions')->where(...)->update(['posting_type' => 'order.paid'])` in a one-off migration.

### 5. (Optional) `posted_at` bounds

The new `PostedAtBoundsValidator` is enabled by default with a 300-second future clock-skew window and no historical floor. If your application backdates postings legitimately by more than 5 minutes or imports historical data, set:

```php
// config/ledger.php
'max_clock_skew_seconds' => 0, // strictly no future-dated
'historical_lower_bound' => '2020-01-01T00:00:00Z',
```

The bounds apply only to new postings; existing rows are not re-validated.

### 6. (Optional, recommended) Audit event listeners

`TransactionPosted` and `TransactionReversed` fire via `DB::afterCommit()` and run synchronously. If you have non-trivial listeners (anything beyond a log line), make them `implements ShouldQueue` so transient failures do not propagate back to `Ledger::post()` callers after the financial write has succeeded. See [Events & Exceptions — Listeners must implement `ShouldQueue`](docs/11-events-and-exceptions.md).

### 7. Octane / Swoole / RoadRunner

No code change required, but the recorder-window safety net is now Fiber-aware so concurrent coroutines no longer share each other's open windows. See [Operations — Running under Octane / Swoole / RoadRunner](docs/09-operations.md).

### Behavioural changes

- `Reference::for()` now rejects parts that contain a `:` character. Prior code that relied on this (none was documented) will need to either swap to multiple positional parts (`Reference::for('order.paid', '1', '2')`) or use `Reference::fromString()` for the legacy form.
- `ledger:verify` and `ledger:rebuild-balances` now run as set-based SQL. Output format and exit codes are unchanged; runtime drops from O(N) to O(1) queries per ledger.
