<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Exceptions\MixedCurrencyException;

/**
 * Every entry in a transaction must use the transaction's declared currency.
 *
 * Multi-currency operations must be expressed as two linked postings, never
 * as a single mixed-currency transaction.
 */
final class SingleCurrencyValidator implements TransactionValidator
{
    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        foreach ($draft->entries as $entry) {
            if ($entry->amount->currency !== $draft->currency) {
                throw MixedCurrencyException::inTransaction($draft->currency, $entry->amount->currency);
            }
        }
    }
}
