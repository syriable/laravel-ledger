# Contributing to Laravel Ledger

Thanks for considering a contribution. Before opening a PR, please read this entire document.

## The package philosophy

Small core. Strict invariants. Database-level enforcement first, application-level enforcement second. Spatie-style simplicity. The public API is three verbs and will stay three verbs.

## What will be rejected

These are not opinions. They are the package's design contract.

1. **Soft deletes anywhere** in `src/Models/`. Financial models are immutable.
2. **New `UPDATE` statements** on `transactions` or `entries` rows. There is exactly one allowed mutation in the entire package (`is_archived` on `Account`). Anything else is a bug.
3. **Removing or reordering required validators.** Config can append. It cannot replace.
4. **Mutable state in Postings.** A Posting that reads `$this->order->total` where `total` is recomputed dynamically will silently corrupt the ledger on retry. Pre-compute monetary values in the constructor.
5. **I/O inside validators.** No DB, no HTTP, no cache. Validators are pure functions of `(draft, accounts)`.
6. **`Carbon::now()` or `CarbonImmutable::now()` outside `SystemClock`.** Inject the `Clock` contract.
7. **`Money` constructed from a float.** Ever. The constructor refuses it.
8. **Multi-ledger transactions.** Postings link via `correlation_id`, not via cross-ledger entries.
9. **Reversal-of-reversal logic.** A reversal cannot be reversed. Post a new operation instead.
10. **`save()` / `update()` / `delete()` on financial models from outside the recorder.** The `WritableOnlyByRecorder` trait will catch this — do not work around it.
11. **Repository-pattern abstractions.** Eloquent is the repository.
12. **A `Services/`, `Application/`, `Domain/`, `Infrastructure/` directory split.** This is a 25-class package, not an enterprise application.
13. **Listeners that write back to the ledger inside the same request.** Enqueue a job.
14. **REST/GraphQL endpoints, Filament resources, or any UI.** Companion package, separate repo.

## What is welcome

- Bug fixes (with a failing test reproducing the bug first).
- Documentation improvements — especially the cookbook of postings.
- Performance improvements in `Recording/` with benchmarks attached.
- Custom validators (kept in your own application, not in core).
- Companion package proposals — open an issue first to discuss scope.

## Test policy

- Every PR must pass `composer test` and `composer analyse` cleanly.
- Every PR that touches `Recording/` requires a new feature test exercising the change.
- Every PR that touches `Validators/` requires a happy-path test and a failure-mode test for every branch added.
- `Money` and `Reference` changes require property-style tests, not just example-based ones.

## Reviewing this list

If you think one of the entries above is wrong, please open an issue rather than a PR. The package's invariants are the reason it exists; loosening them on a per-PR basis isn't viable.

Thank you.
