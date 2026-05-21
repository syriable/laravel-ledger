<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class NonPositiveAmountException extends LedgerException
{
    public static function on(int $index, int $amount): self
    {
        return new self("Entry at index {$index} has non-positive amount ({$amount}). All entry amounts must be > 0.");
    }
}
