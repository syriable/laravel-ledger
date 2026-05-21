# ADR 0004 — `accounts.normal_balance` as a generated column

**Status:** Accepted

**Date:** 2026-01

## Context

An account's normal balance is mechanically derived from its type:

- Asset, Expense → Debit
- Liability, Equity, Revenue → Credit

We could store it as a plain column, computed by the application, or derive it on read.

## Decision

**Stored generated column**, computed by the database from `type`:

```sql
normal_balance VARCHAR(6) GENERATED ALWAYS AS (
  CASE WHEN type IN ('asset','expense') THEN 'debit' ELSE 'credit' END
) STORED
```

## Rationale

- Application code never sets `normal_balance` directly; it's impossible for it to drift from `type`.
- Indexable. Queries like "all credit-normal accounts" are O(log n).
- Survives migrations: if someone backfills accounts in a future migration, the generated column rebuilds automatically.

## Consequences

- The recorder writes `type` and never `normal_balance`.
- After `INSERT`, the model is `refresh()`ed to pick up the database-generated value.
- The Eloquent cast (`'normal_balance' => NormalBalance::class`) treats the string value the same as it would a plain column.

## Driver compatibility

| Driver | Support |
| --- | --- |
| Postgres 12+ | `GENERATED ALWAYS AS (...) STORED` — native |
| MySQL 5.7+ / 8 | `AS (...) STORED` — native |
| SQLite 3.31+ | `AS (...) STORED` — native |

All supported drivers handle the CASE expression and the IN clause portably.
