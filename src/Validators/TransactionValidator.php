<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Models\Account;

/**
 * A TransactionValidator is a pure check applied to a TransactionDraft.
 *
 * Validators receive the draft AND the already-resolved accounts collection
 * (keyed by id). They MUST NOT perform any I/O. They MUST NOT mutate either
 * the draft or the accounts. They throw a LedgerException on violation;
 * silence means pass.
 *
 * Precondition guaranteed by the recorder before the pipeline runs: every
 * accountId referenced by $draft->entries appears as a key in $accounts.
 * Validators may rely on this without re-checking — the recorder's
 * AccountNotFoundException already covers the missing-account case.
 *
 * Validators may NEVER weaken the package's required invariants — extension
 * validators can only add additional checks on top of the defaults.
 */
interface TransactionValidator
{
    /**
     * @param  Collection<string, Account>  $accounts  Keyed by account id.
     */
    public function validate(TransactionDraft $draft, Collection $accounts): void;
}
