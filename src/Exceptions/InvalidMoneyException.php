<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

/**
 * Thrown when a Money value object is constructed or operated on illegally:
 * negative minor units, malformed currency, or cross-currency arithmetic.
 */
final class InvalidMoneyException extends LedgerException
{
    public static function negativeAmount(int $minorUnits): self
    {
        return new self("Money amounts must be non-negative, got {$minorUnits}.");
    }

    public static function invalidCurrency(string $currency): self
    {
        return new self("Currency must be an ISO 4217 three-letter uppercase code, got '{$currency}'.");
    }

    public static function currencyMismatch(string $left, string $right): self
    {
        return new self("Cannot operate across currencies: {$left} vs {$right}.");
    }

    public static function underflow(int $left, int $right): self
    {
        return new self("Money subtraction would underflow: {$left} - {$right} < 0.");
    }
}
