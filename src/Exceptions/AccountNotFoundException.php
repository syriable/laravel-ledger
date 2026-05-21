<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class AccountNotFoundException extends LedgerException
{
    /**
     * @param  list<string>  $missingIds
     */
    public static function ids(array $missingIds): self
    {
        $list = implode(', ', $missingIds);

        return new self("One or more accounts referenced by the draft were not found: {$list}.");
    }

    public static function byCode(string $code, string $ledgerSlug): self
    {
        return new self("Account with code '{$code}' not found in ledger '{$ledgerSlug}'.");
    }
}
