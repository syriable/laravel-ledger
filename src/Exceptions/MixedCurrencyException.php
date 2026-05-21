<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class MixedCurrencyException extends LedgerException
{
    public static function inTransaction(string $declared, string $offending): self
    {
        return new self(
            "Transaction declared currency '{$declared}' but contains an entry with currency '{$offending}'. ".
            'Cross-currency operations must be expressed as two linked single-currency postings.'
        );
    }
}
