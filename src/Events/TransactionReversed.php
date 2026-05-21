<?php

declare(strict_types=1);

namespace Syriable\Ledger\Events;

use Syriable\Ledger\Models\Transaction;

/**
 * Dispatched after a reversal transaction commits. Carries both the reversal
 * and the original it compensates.
 */
final readonly class TransactionReversed
{
    public function __construct(
        public Transaction $reversal,
        public Transaction $original,
    ) {}
}
