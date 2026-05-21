<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class MinimumEntriesNotMetException extends LedgerException
{
    public static function with(int $count): self
    {
        return new self("A transaction must contain at least 2 entries, got {$count}.");
    }
}
