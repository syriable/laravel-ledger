# ADR 0005 — `correlation_id` on transactions, `tenant_id` on ledgers, shipped from v0.1

**Status:** Accepted

**Date:** 2026-01

## Context

Two columns appear to be unnecessary in v0.1:

- `transactions.correlation_id` — for grouping linked operations (FX leg pairs, multi-step transfers).
- `ledgers.tenant_id` — for multi-tenant deployments where one tenant owns multiple ledgers.

Neither is consumed by core code today. We could omit them and add them later via migrations.

## Decision

**Ship both columns in v0.1**, nullable, indexed, with no behaviour in core.

## Rationale

- Adding columns to a populated financial table later is operationally expensive (long-running migrations, table locks, multi-step deploys). Adding them on day one is free.
- Both columns have well-understood semantics that won't change with use.
- A future companion package (`laravel-ledger-fx` for `correlation_id`, multi-tenancy concerns for `tenant_id`) can consume them without requiring a core schema migration.

## Consequences

- The migrations carry two columns that are unused in v0.1. Both are nullable; neither imposes any cost on consumers who don't use them.
- The `transactions.correlation_id` column is indexed. The `ledgers.tenant_id` column is indexed.
- `Posting::correlationId()` exists as an overridable method, defaulting to `null`.

## What we did not do

- We did not commit to any specific tenant-isolation strategy. `tenant_id` exists; how it's used (per-tenant connection routing, row-level security, application-level scoping) is left to the consumer or a future companion.
- We did not ship any FX logic. The column is the *enabler*; the engine lives elsewhere.
