<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

final class LedgerNotFoundException extends LedgerException
{
    public static function bySlug(string $slug): self
    {
        return new self("No ledger found with slug '{$slug}'.");
    }

    public static function byId(string $id): self
    {
        return new self("No ledger found with id {$id}.");
    }
}
