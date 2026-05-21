<?php

declare(strict_types=1);

namespace Syriable\Ledger\Events;

use Syriable\Ledger\Models\Transaction;

/**
 * Dispatched exactly once, after the recorder's DB transaction commits.
 *
 * Listeners are forbidden from writing back to the ledger inside the same
 * request — if a follow-up posting is needed, enqueue a job.
 */
final readonly class TransactionPosted
{
    public function __construct(
        public Transaction $transaction,
    ) {}
}
