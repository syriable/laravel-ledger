<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

/**
 * Lightweight DTO returned by IdempotencyStore::find().
 *
 * Holds only the identifier of the prior Transaction so non-database
 * implementations (Redis, in-memory caches in companion packages) can
 * satisfy the contract without rehydrating a full Eloquent model.
 *
 * The recorder loads the full Transaction by primary key when it needs
 * to surface it via PostingResult.
 */
final readonly class IdempotencyMatch
{
    public function __construct(
        public string $transactionId,
    ) {}
}
