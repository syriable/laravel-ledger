<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class AccountCurrencyMismatchException extends LedgerException
{
    public static function on(string $accountId, string $accountCurrency, string $entryCurrency): self
    {
        return new self(
            "Entry currency '{$entryCurrency}' does not match account {$accountId}'s currency '{$accountCurrency}'."
        );
    }
}
