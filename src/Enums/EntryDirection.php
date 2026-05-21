<?php

declare(strict_types=1);

namespace Syriable\Ledger\Enums;

/**
 * EntryDirection — every entry is either a debit or a credit on one account.
 *
 * Sign of money flow is determined by combining EntryDirection with
 * the account's NormalBalance — see Account::signedAmountOf().
 */
enum EntryDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
