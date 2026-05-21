<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class DuplicateReferenceException extends LedgerException
{
    public static function for(string $ledgerId, string $reference): self
    {
        return new self("A transaction with reference '{$reference}' already exists in ledger {$ledgerId}.");
    }
}
