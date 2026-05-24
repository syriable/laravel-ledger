<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

use Throwable;

/**
 * Thrown when the TransactionRecorder exhausts its deadlock-retry budget,
 * or when an unexpected QueryException escapes the write path that the
 * recorder could not classify as a duplicate-reference replay.
 *
 * Wrapping these failures lets consumers catch every package-level write
 * failure with `catch (LedgerException $e)` instead of having to special-case
 * driver-specific SQLSTATE codes from a raw QueryException.
 */
final class LedgerWriteFailedException extends LedgerException
{
    public static function afterRetries(string $ledgerId, string $reference, int $attempts, Throwable $previous): self
    {
        return new self(
            "TransactionRecorder failed to record reference '{$reference}' in ledger {$ledgerId} ".
            "after {$attempts} attempt(s). Previous: ".$previous->getMessage(),
            0,
            $previous,
        );
    }

    public static function unexpected(string $ledgerId, string $reference, Throwable $previous): self
    {
        return new self(
            "TransactionRecorder raised an unexpected database failure recording reference '{$reference}' ".
            "in ledger {$ledgerId}: ".$previous->getMessage(),
            0,
            $previous,
        );
    }
}
