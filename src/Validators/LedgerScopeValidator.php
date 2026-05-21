<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Exceptions\AccountNotFoundException;
use Syriable\Ledger\Exceptions\LedgerScopeViolationException;

/**
 * Every account referenced by the draft must belong to the same ledger
 * as the transaction. No financial relationship crosses ledgers.
 */
final class LedgerScopeValidator implements TransactionValidator
{
    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        foreach ($draft->entries as $entry) {
            $account = $accounts->get($entry->accountId);

            if ($account === null) {
                throw AccountNotFoundException::ids([$entry->accountId]);
            }

            if ($account->ledger_id !== $draft->ledgerId) {
                throw LedgerScopeViolationException::on(
                    $account->id,
                    $draft->ledgerId,
                    $account->ledger_id,
                );
            }
        }
    }
}
