<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Default IdempotencyStore — reads transactions.reference directly.
 *
 * No separate idempotency_keys table is needed because transactions.reference
 * is already UNIQUE(ledger_id, reference). One source of truth, one read.
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function find(string $ledgerId, Reference $reference): ?Transaction
    {
        /** @var Transaction|null $tx */
        $tx = Transaction::query()
            ->where('ledger_id', $ledgerId)
            ->where('reference', (string) $reference)
            ->first();

        return $tx;
    }
}
