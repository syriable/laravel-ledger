<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class ImbalancedTransactionException extends LedgerException
{
    public static function with(int $debits, int $credits): self
    {
        return new self(
            "Transaction is not balanced: sum of debits ({$debits}) ".
            "does not equal sum of credits ({$credits})."
        );
    }
}
