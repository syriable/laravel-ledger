# Invariants

Every rule below is enforced at the **validator level**, the **database constraint level**, or both. They cannot be relaxed by configuration.

## Append-only (for financial data)

No financial column on `transactions` or `entries` is ever mutated. Once a transaction is recorded, the only allowed write involving it is the insertion of a **new** transaction that compensates for it.

There is exactly one mutable column on a financial model: `accounts.is_archived`. Archiving an account doesn't change any historical entry.

## Always balanced

For every transaction:

```
Σ entries where direction = debit  ==  Σ entries where direction = credit
```

The `BalancedTransactionValidator` rejects any imbalanced draft before insertion.

## Single currency per transaction

Every entry in a transaction shares the transaction's declared currency. To move value across currencies, post **two** linked transactions joined by `correlation_id`:

```php
// First posting: USD side
Ledger::post(new FxOutgoingPosting($amount_usd, $correlationId));
// Second posting: EUR side
Ledger::post(new FxIncomingPosting($amount_eur, $correlationId));
```

Both postings must be balanced *internally*. The cross-currency mathematics live in the consumer's FX module, not in core.

## Entry currency must match account currency

If an account's currency is USD, the only entry currency it accepts is USD. Account currency is immutable, so this invariant is permanent.

## All entries belong to the same ledger

No cross-ledger entries. If you need a transfer between ledgers, post two transactions joined by `correlation_id`.

## Amounts are positive integers

Stored as `BIGINT UNSIGNED`. Direction is encoded by `EntryDirection`, never by sign. `Money` refuses floats and negatives at construction time.

## Every transaction has a unique idempotency reference

`UNIQUE(ledger_id, reference)` is the physical guarantee. Replaying the same Posting twice returns the same Transaction with `wasReplayed = true`.

## Archived accounts reject new entries

Exception: reversals are allowed against archived accounts, because the books closing out against an archived account must still settle.

## Reversals are new transactions

The original is never mutated. Reversal status is derived from `SELECT * FROM transactions WHERE reverses_transaction_id = ?`.

## A transaction can be reversed at most once

`UNIQUE(reverses_transaction_id)` enforces this at the database level. NULLs are distinct in all supported drivers (Postgres, MySQL 8, SQLite), so ordinary postings remain unaffected.

## A reversal cannot itself be reversed

If you need to re-apply the original effect, post a new operation. Re-reversing would muddle the audit trail and is not supported.

## Soft-deletes are forbidden

No `SoftDeletes` trait on any financial model. Deletion of any kind is forbidden by the `WritableOnlyByRecorder` trait, which throws on any `delete()` call.

## Money never crosses the float boundary

The `Money` constructor accepts `int` and `string` (currency only). Float values trigger a TypeError at the language level.

---

## What enforces what

| Invariant | Validator | DB constraint |
| --- | --- | --- |
| Balanced | `BalancedTransactionValidator` | (PHP only) |
| Single currency | `SingleCurrencyValidator` | (PHP only) |
| Entry/account currency match | `AccountCurrencyMatchValidator` | (PHP only) |
| Cross-ledger forbidden | `LedgerScopeValidator` | (PHP only) |
| Archived rejection | `AccountStateValidator` | (PHP only) |
| Min 2 entries | `MinimumEntriesValidator` | (PHP only) |
| Positive amount | `PositiveAmountValidator` | `CHECK (amount > 0)` (Postgres) |
| Unique reference | (none) | `UNIQUE(ledger_id, reference)` |
| Once-only reversal | (none) | `UNIQUE(reverses_transaction_id)` |
| Currency format | (none) | `CHECK (currency ~ '^[A-Z]{3}$')` (Postgres) |
| Direction valid | (none) | `CHECK (direction IN ('debit','credit'))` (Postgres) |
| Append-only | `WritableOnlyByRecorder` trait | (revoke `UPDATE`/`DELETE` at the DB role level, recommended for prod) |
