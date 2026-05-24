<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Exceptions\AccountCurrencyMismatchException;
use Syriable\Ledger\Models\Account;

/**
 * Each entry's currency must match the currency of the account it touches.
 *
 * Account currency is immutable at the schema level, so this validator
 * cannot be defeated by re-denominating an account mid-flight.
 */
final class AccountCurrencyMatchValidator implements TransactionValidator
{
    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        foreach ($draft->entries as $entry) {
            /** @var Account $account Recorder guarantees presence; see TransactionValidator docblock. */
            $account = $accounts->get($entry->accountId);

            if ($account->currency !== $entry->amount->currency) {
                throw AccountCurrencyMismatchException::on(
                    $account->id,
                    $account->currency,
                    $entry->amount->currency,
                );
            }
        }
    }
}
