<?php

declare(strict_types=1);

namespace Syriable\Ledger\Data;

use Syriable\Ledger\Models\Transaction;

/**
 * The result of Ledger::post().
 *
 * `wasReplayed` is true when the same Reference had already produced a
 * transaction; in that case `$transaction` is the original, and no new
 * write happened.
 */
final readonly class PostingResult
{
    public function __construct(
        public Transaction $transaction,
        public bool $wasReplayed,
    ) {}
}
