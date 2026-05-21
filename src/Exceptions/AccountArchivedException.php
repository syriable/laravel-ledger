<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class AccountArchivedException extends LedgerException
{
    public static function on(string $accountId): self
    {
        return new self("Account {$accountId} is archived and cannot accept new entries.");
    }
}
