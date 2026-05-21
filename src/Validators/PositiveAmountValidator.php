<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Exceptions\NonPositiveAmountException;

/**
 * Every entry amount must be strictly > 0.
 *
 * Money already refuses negative construction; this check additionally
 * rejects zero, since zero entries are noise that bloats the ledger.
 */
final class PositiveAmountValidator implements TransactionValidator
{
    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        foreach ($draft->entries as $index => $entry) {
            if ($entry->amount->minorUnits <= 0) {
                throw NonPositiveAmountException::on($index, $entry->amount->minorUnits);
            }
        }
    }
}
