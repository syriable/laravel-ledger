# ADR 0003 — `transactions.reference` is the only idempotency store

**Status:** Accepted

**Date:** 2026-01

## Context

The original blueprint proposed two layers of idempotency:

1. A `UNIQUE(ledger_id, reference)` constraint on `transactions`.
2. A separate `idempotency_keys` table mapping external keys to transaction IDs.

The second layer was justified as "so external keys (webhook IDs, gateway IDs) can be mapped without polluting the financial reference."

## Decision

**Do not ship `idempotency_keys` in v1.** The `transactions.reference` column is the only idempotency store.

External keys are embedded directly into the reference via the scope mechanism:

```php
Reference::for('stripe.event', $event->id);   // stripe.event:evt_abc
Reference::for('adyen.notification', $id);    // adyen.notification:NOT_xyz
```

The `Reference` value object enforces the scope-must-have-a-dot rule, eliminating the collision concern that motivated the second table.

## Rationale

- One table, one source of truth. No risk of the two layers disagreeing.
- The `IdempotencyStore` contract still exists, and the default DB impl reads `transactions.reference` directly — so a future Redis-cached impl can be added without schema changes.
- Re-adding `idempotency_keys` later (if a use case emerges for indexing external keys *separately* from the financial reference) is non-breaking.

## Consequences

- All idempotency lookups hit `transactions.reference`. Indexed; fast.
- Webhook handlers compose their references via `Reference::for($source.scope, $externalId)`.
- The schema is one table smaller.

## What we did not do

- We did not drop the `IdempotencyStore` contract. The contract is a one-method interface that costs nothing to keep, and a Redis-cached implementation is a likely v0.5 addition for high-throughput consumers.
