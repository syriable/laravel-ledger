<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Idempotency store — finds a previously-recorded transaction by reference
 * before the recorder enters the DB transaction.
 *
 * This is an OPTIMISATION layer. The physical truth is the
 * UNIQUE(ledger_id, reference) constraint on the transactions table;
 * the store exists only to short-circuit replays cheaply.
 *
 * Custom implementations (e.g. Redis-cached) may ship in companion packages.
 */
interface IdempotencyStore
{
    /**
     * Find a transaction previously recorded under this (ledgerId, reference)
     * pair. Returns null if none exists.
     */
    public function find(string $ledgerId, Reference $reference): ?Transaction;
}
