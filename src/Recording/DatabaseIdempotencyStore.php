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
 *
 * Returns a lightweight IdempotencyMatch DTO so the contract is honest for
 * non-DB implementations; the recorder hydrates the full Transaction when
 * it needs it.
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function find(string $ledgerId, Reference $reference): ?IdempotencyMatch
    {
        $row = Transaction::query()
            ->where('ledger_id', $ledgerId)
            ->where('reference', (string) $reference)
            ->select('id')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var string $id */
        $id = $row->id;

        return new IdempotencyMatch($id);
    }
}
