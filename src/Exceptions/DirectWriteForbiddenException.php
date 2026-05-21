<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

/**
 * Thrown when a financial model's save()/update()/delete() is called outside
 * the TransactionRecorder's open window.
 *
 * The TransactionRecorder is the ONLY code allowed to write to ledgers,
 * accounts, transactions, entries, and balances. Anywhere else, attempting to
 * persist or mutate these models throws.
 */
final class DirectWriteForbiddenException extends LedgerException
{
    public static function on(string $model, string $operation): self
    {
        return new self(
            "Direct {$operation}() on {$model} is forbidden. ".
            'Financial models can only be persisted by the TransactionRecorder. '.
            'Use Ledger::post() / Ledger::reverse() / Ledger::openAccount() instead.'
        );
    }
}
