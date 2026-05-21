<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Exceptions\MinimumEntriesNotMetException;

/**
 * A transaction must contain at least two entries (one debit, one credit).
 * Cheap check; runs first.
 */
final class MinimumEntriesValidator implements TransactionValidator
{
    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        $count = count($draft->entries);
        if ($count < 2) {
            throw MinimumEntriesNotMetException::with($count);
        }
    }
}
