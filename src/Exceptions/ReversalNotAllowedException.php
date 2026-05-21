<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class ReversalNotAllowedException extends LedgerException
{
    public static function alreadyReversed(string $transactionId): self
    {
        return new self("Transaction {$transactionId} has already been reversed; a transaction may be reversed at most once.");
    }

    public static function cannotReverseAReversal(string $transactionId): self
    {
        return new self(
            "Transaction {$transactionId} is itself a reversal and cannot be reversed. ".
            'To re-apply the original effect, post a new operation instead.'
        );
    }
}
