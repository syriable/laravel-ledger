# ADR 0002 — No `reversed_by_transaction_id` back-link

**Status:** Accepted

**Date:** 2026-01

## Context

The original architectural blueprint had two columns on `transactions`:

- `reverses_transaction_id` — set on the reversal, pointing at the original.
- `reversed_by_transaction_id` — set on the original, pointing at its reversal.

The back-link was useful for answering "is this transaction reversed?" without a join, but required an `UPDATE` on the original row — the only mutation in an otherwise append-only system.

## Decision

**Drop `reversed_by_transaction_id`** from the schema. "Is this transaction reversed?" is answered by an indexed lookup:

```sql
SELECT 1 FROM transactions WHERE reverses_transaction_id = ?
```

Plus `UNIQUE(reverses_transaction_id)` enforces the "at most one reversal per original" rule at the database level.

## Rationale

- The package's append-only invariant is now literally true. No financial-table `UPDATE`s anywhere.
- "Reversed at most once" moves from an application-level check to a database-level guarantee.
- The cost is one extra `SELECT` when asking the question, which is rare (admin/reporting paths only).

## Consequences

- `Transaction::isReversed()` performs an indexed existence check via the `reversal()` hasOne relation.
- `Transaction::state()` is derived, not stored.
- The single `UPDATE` that the original blueprint allowed (the back-link write) is no longer needed; the `WritableOnlyByRecorder` trait now disallows updates to `Transaction` and `Entry` entirely.

## Trade-off accepted

- One extra index column (`reverses_transaction_id` as `UNIQUE`) on a table that already had it.
- One small read query when checking reversal status. Cached via the relation in most call sites.
