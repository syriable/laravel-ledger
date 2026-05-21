# ADR 0001 — Projection strategy: synchronous, swappable

**Status:** Accepted

**Date:** 2026-01

## Context

Balances can be (a) computed from entries on every read, (b) materialised in a projection table that's updated incrementally inside the recorder's DB transaction, or (c) materialised asynchronously by a queue worker.

Each option trades different costs:

- **(a) Computed on read** — zero write contention, but O(N) per read on hot accounts.
- **(b) Sync projection** — O(1) reads, but serialises postings against the same account (the platform cash account becomes a global write lock).
- **(c) Async projection** — O(1) reads, no write contention, but eventually-consistent: a fresh post is invisible in the projection until the worker catches up.

## Decision

The default `BalanceProjector` is **synchronous and in-transaction** (option b). The contract is **swappable from v0.1**: consumers bind their own `BalanceProjector` implementation in a service provider.

## Rationale

- Most applications never reach the contention level where (b) becomes a problem.
- A synchronous projection makes the package's "read-after-write" behaviour intuitive: post → read → see the new balance.
- Making the contract swappable from day one means hot-account victims aren't forced to refactor the package. They write a custom projector.

## Consequences

- The `balances` table exists and is updated by the recorder, every time, atomically with the entries.
- `ledger:verify` and `ledger:rebuild-balances` exist as the safety net if the projection drifts.
- High-throughput consumers can write an async projector and bind it; tests against the projector contract are shipped with the package (forthcoming).

## What we did not do

- We did **not** ship without a projection table. Recomputing from entries on every read makes the simple cases unnecessarily expensive.
- We did **not** lock the projection strategy as a v1 decision. Companion packages can replace it without modifying core.
