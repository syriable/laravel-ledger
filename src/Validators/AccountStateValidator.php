<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Exceptions\AccountArchivedException;
use Syriable\Ledger\Exceptions\AccountNotFoundException;

/**
 * Archived accounts reject new entries — but retain history.
 *
 * Reversals are exempt: a reversal of a transaction whose accounts have
 * since been archived must still succeed (you cannot lock an archived
 * account against the books closing out against it). This exemption is
 * checked by inspecting the draft for a reverses_transaction_id.
 */
final class AccountStateValidator implements TransactionValidator
{
    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        // Reversals are allowed against archived accounts — see class docblock.
        if ($draft->reversesTransactionId !== null) {
            return;
        }

        foreach ($draft->entries as $entry) {
            $account = $accounts->get($entry->accountId);

            if ($account === null) {
                throw AccountNotFoundException::ids([$entry->accountId]);
            }

            if ($account->is_archived) {
                throw AccountArchivedException::on($account->id);
            }
        }
    }
}
