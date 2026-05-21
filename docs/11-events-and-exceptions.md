# Events & Exceptions

A complete reference to every event the package dispatches and every exception it throws.

## Events

The package dispatches four events. All live in the `Syriable\Ledger\Events` namespace. All are plain, immutable data objects — no `ShouldBroadcast`, no queue interface; subscribe with ordinary Laravel listeners.

### `TransactionPosted`

Dispatched after an ordinary (non-reversal) transaction is committed.

```php
final readonly class TransactionPosted
{
    public Transaction $transaction;
}
```

Fired **after the database transaction commits**, via `DB::afterCommit()`. By the time a listener runs, the transaction and its entries are durable — a listener can safely read them. It fires exactly once per posted transaction.

### `TransactionReversed`

Dispatched after a reversal transaction is committed.

```php
final readonly class TransactionReversed
{
    public Transaction $reversal;   // the new compensating transaction
    public Transaction $original;   // the transaction that was reversed
}
```

A reversal does **not** also fire `TransactionPosted`. Reversals get their own event so listeners can treat them distinctly.

### `AccountOpened`

Dispatched when a new account is created via `Ledger::openAccount()` (or the `HasAccounts` trait, or `LedgerScope::openAccount()`).

```php
final readonly class AccountOpened
{
    public Account $account;
}
```

Opening an account idempotently — i.e. the account already existed — does **not** fire the event. It fires only on genuine creation.

### `AccountArchived`

Dispatched when an account is archived via `Ledger::archiveAccount()`.

```php
final readonly class AccountArchived
{
    public Account $account;
}
```

Archiving an already-archived account does not re-fire the event.

### Listening to events

Register listeners the standard Laravel way:

```php
use Syriable\Ledger\Events\TransactionPosted;
use Illuminate\Support\Facades\Event;

Event::listen(function (TransactionPosted $event): void {
    // e.g. update a read model, send a notification, reindex search
    Log::info("Transaction {$event->transaction->id} posted.");
});
```

### The one hard rule for listeners

**A listener must never write back to the ledger inside the same request.**

Do not call `Ledger::post()` or `Ledger::reverse()` from inside a listener synchronously. The recorder's transaction has already committed by the time the event fires; a synchronous write-back starts a *new* recorder transaction nested in the event dispatch, which makes failure handling ambiguous and can produce partial state.

If an event must trigger a follow-up posting, **dispatch a queued job** and let a worker call `Ledger::post()` on the next tick:

```php
Event::listen(function (TransactionPosted $event): void {
    ProcessLedgerFollowUp::dispatch($event->transaction->id);  // queued — correct
});
```

This keeps each recorder transaction tight and independently recoverable.

## Exceptions

Every exception the package throws extends `Syriable\Ledger\Exceptions\LedgerException`, which extends `\RuntimeException`. You can catch all package errors with a single `catch (LedgerException $e)`, or catch specific types.

`LedgerException` itself is `abstract` — it is never thrown directly, only its subclasses are.

### Validation exceptions

These are thrown by the validator pipeline before anything is written. The draft never reaches the database.

| Exception | Thrown when |
| --- | --- |
| `MinimumEntriesNotMetException` | A transaction has fewer than 2 entries. |
| `NonPositiveAmountException` | An entry's amount is not strictly greater than 0. |
| `MixedCurrencyException` | An entry's currency differs from the transaction's declared currency. |
| `LedgerScopeViolationException` | An entry references an account in a different ledger. |
| `AccountCurrencyMismatchException` | An entry's currency differs from its account's currency. |
| `AccountArchivedException` | A non-reversal posting references an archived account. |
| `ImbalancedTransactionException` | The sum of debits does not equal the sum of credits. |

### Recorder exceptions

Thrown by the recorder around the write itself.

| Exception | Thrown when |
| --- | --- |
| `AccountNotFoundException` | An account referenced by the draft (or looked up by code) does not exist. |
| `DuplicateReferenceException` | A reference collided in a way that is not a clean idempotent replay. |
| `ReversalNotAllowedException` | A transaction is reversed twice, or a reversal is itself reversed. |

> Note: a *normal* idempotent replay — posting the same reference twice — is **not** an exception. It returns a `PostingResult` with `wasReplayed = true`. `DuplicateReferenceException` is reserved for genuinely inconsistent collisions.

### Lifecycle & lookup exceptions

| Exception | Thrown when |
| --- | --- |
| `LedgerNotFoundException` | `Ledger::for($slug)` or a posting references a ledger slug that does not exist. |
| `AccountNotFoundException` | `account($code)` is called for a code that does not exist in the ledger. |

### Value object exceptions

`InvalidMoneyException` is thrown by the `Money` value object:

| Trigger | Detail |
| --- | --- |
| Negative amount | `Money::of(-1, 'USD')` — amounts must be ≥ 0. |
| Bad currency code | Not a 3-letter uppercase ISO 4217 code. |
| Cross-currency arithmetic | `plus()` / `minus()` between different currencies. |
| Underflow | `minus()` producing a result below zero. |

`Reference` and `AccountCode` throw `\InvalidArgumentException` (not a `LedgerException`) for malformed input — an undotted reference scope, an empty part, an uppercase account code. These are programming errors, caught in development, not runtime financial conditions.

### The safety exception

`DirectWriteForbiddenException` is thrown when application code tries to `save()`, `update()`, `delete()`, or `forceDelete()` a financial model (`Ledger`, `Account`, `Transaction`, `Entry`, `Balance`) **outside** the `TransactionRecorder`.

```php
$transaction->update(['amount' => 999]);   // throws DirectWriteForbiddenException
$entry->delete();                          // throws DirectWriteForbiddenException
```

This is the package catching the single most dangerous mistake — bypassing the recorder — before it can corrupt anything. If you see this exception, the fix is never to "work around it": it is to route the change through `Ledger::post()` or `Ledger::reverse()`. There is no legitimate reason for application code to write a financial model directly.

### Handling exceptions

```php
use Syriable\Ledger\Exceptions\LedgerException;
use Syriable\Ledger\Exceptions\ImbalancedTransactionException;
use Syriable\Ledger\Exceptions\AccountArchivedException;

try {
    Ledger::post($posting);
} catch (ImbalancedTransactionException $e) {
    // a bug in the Posting — debits != credits
    report($e);
} catch (AccountArchivedException $e) {
    // expected business condition — surface it to the user
    return back()->withErrors('That account is closed.');
} catch (LedgerException $e) {
    // any other ledger error
    report($e);
}
```

Most validation exceptions indicate a **bug in your Posting** and should be reported, not shown to users. `AccountArchivedException` is the common exception to a real business condition you may want to surface gracefully.
