<?php

declare(strict_types=1);

namespace Syriable\Ledger\ValueObjects;

use InvalidArgumentException;

/**
 * AccountCode — a stable, dotted, lowercase identifier for an account
 * within a ledger (e.g. "platform.cash.usd", "available.usd", "escrow.usd").
 *
 * Codes are the human-readable handle. Stable across renames. Unique per ledger.
 *
 * @psalm-immutable
 */
final readonly class AccountCode
{
    public const PATTERN = '/^[a-z][a-z0-9]*(?:\.[a-z0-9]+)*$/';

    public function __construct(
        public string $value,
    ) {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Account code '{$value}' must be dot-separated lowercase alphanumerics (e.g. 'platform.cash.usd')."
            );
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
