<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Syriable\Ledger\ValueObjects\Reference;

/**
 * Idempotency store — finds a previously-recorded transaction by reference
 * before the recorder enters the DB transaction.
 *
 * This is an OPTIMISATION layer. The physical truth is the
 * UNIQUE(ledger_id, reference) constraint on the transactions table; the
 * store exists only to short-circuit replays cheaply.
 *
 * Returns an IdempotencyMatch DTO (carrying just the transaction id)
 * rather than the full Eloquent Transaction so non-database implementations
 * (Redis caches, in-memory stores in companion packages) can satisfy the
 * contract without rehydrating an Eloquent model from another datasource.
 * The recorder loads the Transaction by primary key when it needs to
 * surface it.
 */
interface IdempotencyStore
{
    /**
     * Find an IdempotencyMatch for a previously-recorded transaction under
     * this (ledgerId, reference) pair. Returns null if none exists.
     */
    public function find(string $ledgerId, Reference $reference): ?IdempotencyMatch;
}
