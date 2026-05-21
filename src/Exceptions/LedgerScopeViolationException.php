<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class LedgerScopeViolationException extends LedgerException
{
    public static function on(string $accountId, string $expectedLedgerId, string $actualLedgerId): self
    {
        return new self(
            "Account {$accountId} belongs to ledger {$actualLedgerId} but the transaction is for ledger {$expectedLedgerId}. ".
            'Cross-ledger entries are forbidden.'
        );
    }
}
