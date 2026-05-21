<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Exceptions\ImbalancedTransactionException;

/**
 * Σ debits == Σ credits.
 *
 * This is the central invariant of double-entry accounting and the reason
 * this package exists. Runs last because it is the most expensive check
 * and we want cheap structural failures to surface first.
 */
final class BalancedTransactionValidator implements TransactionValidator
{
    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        $debits = 0;
        $credits = 0;

        foreach ($draft->entries as $entry) {
            if ($entry->direction === EntryDirection::Debit) {
                $debits += $entry->amount->minorUnits;
            } else {
                $credits += $entry->amount->minorUnits;
            }
        }

        if ($debits !== $credits) {
            throw ImbalancedTransactionException::with($debits, $credits);
        }
    }
}
