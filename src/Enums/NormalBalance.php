<?php

declare(strict_types=1);

namespace Syriable\Ledger\Enums;

/**
 * NormalBalance — the side on which an account normally carries a positive
 * balance. Derived from AccountType, stored on the row as a generated column
 * for indexability.
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
