<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Models\Account;

/**
 * Runs validators in declared order. Required validators (defined in the
 * service provider) ALWAYS run first; user-configured additions only ever
 * append to the list. The required set cannot be removed or reordered.
 */
final class ValidatorPipeline
{
    /**
     * @param  list<TransactionValidator>  $validators  Required validators first, additions second.
     */
    public function __construct(
        private readonly array $validators,
    ) {}

    /**
     * @param  Collection<string, Account>  $accounts
     */
    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        foreach ($this->validators as $validator) {
            $validator->validate($draft, $accounts);
        }
    }
}
