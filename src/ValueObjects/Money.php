<?php

declare(strict_types=1);

namespace Syriable\Ledger\ValueObjects;

use Syriable\Ledger\Exceptions\InvalidMoneyException;

/**
 * Money — integer minor units + ISO 4217 currency code.
 *
 * Money is the ONLY allowed monetary type in the ledger. Float never crosses
 * this boundary. Amounts are non-negative; direction is encoded by Debit/Credit
 * entries, never by sign.
 *
 * @psalm-immutable
 */
final readonly class Money
{
    /**
     * @param  int<0, max>  $minorUnits  Always non-negative; sign lives in EntryDirection.
     * @param  non-empty-string  $currency  Uppercase ISO 4217 three-letter code.
     */
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {}

    /**
     * Construct from minor units (e.g. cents). The package never accepts floats.
     *
     * @throws InvalidMoneyException
     */
    public static function of(int $minorUnits, string $currency): self
    {
        if ($minorUnits < 0) {
            throw InvalidMoneyException::negativeAmount($minorUnits);
        }

        $currency = self::normaliseCurrency($currency);

        return new self($minorUnits, $currency);
    }

    /**
     * Zero in a given currency.
     *
     * @throws InvalidMoneyException
     */
    public static function zero(string $currency): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    /**
     * @throws InvalidMoneyException
     */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    /**
     * @throws InvalidMoneyException
     */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        $result = $this->minorUnits - $other->minorUnits;
        if ($result < 0) {
            throw InvalidMoneyException::underflow($this->minorUnits, $other->minorUnits);
        }

        return new self($result, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency === $other->currency;
    }

    public function sameCurrencyAs(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    /**
     * Inspectable representation, e.g. "12345 USD". Not a display format —
     * use a presenter in your app layer for that.
     */
    public function __toString(): string
    {
        return $this->minorUnits.' '.$this->currency;
    }

    /**
     * @throws InvalidMoneyException
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw InvalidMoneyException::currencyMismatch($this->currency, $other->currency);
        }
    }

    /**
     * @return non-empty-string
     *
     * @throws InvalidMoneyException
     */
    private static function normaliseCurrency(string $currency): string
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw InvalidMoneyException::invalidCurrency($currency);
        }

        /** @var non-empty-string $currency */
        return $currency;
    }
}
