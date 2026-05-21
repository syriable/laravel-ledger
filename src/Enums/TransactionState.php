<?php

declare(strict_types=1);

namespace Syriable\Ledger\Enums;

/**
 * TransactionState — a transaction is either Posted or Reversed.
 *
 * State is DERIVED, not stored: a transaction is Reversed iff another
 * transaction exists whose `reverses_transaction_id` points back to it.
 * No mutation of the original transaction is permitted.
 */
enum TransactionState: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';
}
