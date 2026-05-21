<?php

declare(strict_types=1);

namespace Syriable\Ledger\ValueObjects;

use InvalidArgumentException;

/**
 * Reference — the idempotency key for a transaction.
 *
 * References MUST be dot-scoped (e.g. "order.paid:1234") to prevent collisions
 * across business domains. This is the only protection against two unrelated
 * operations accidentally minting the same key.
 *
 * @psalm-immutable
 */
final readonly class Reference
{
    public const SCOPE_PATTERN = '/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+$/';

    private function __construct(
        public string $value,
    ) {}

    /**
     * Build a reference from a dot-scoped namespace plus one or more parts.
     *
     * Example:
     *   Reference::for('order.paid', $order->id)         // "order.paid:42"
     *   Reference::for('stripe.event', $event->id)       // "stripe.event:evt_..."
     *   Reference::for('payout.settled', $sellerId, $i)  // "payout.settled:7:3"
     *
     * @throws InvalidArgumentException When the scope is missing its dot, empty,
     *                                  or when any part is empty / whitespace.
     */
    public static function for(string $scope, string|int ...$parts): self
    {
        self::assertValidScope($scope);

        if ($parts === []) {
            throw new InvalidArgumentException("Reference requires at least one part after the scope '{$scope}'.");
        }

        $cleaned = [];
        foreach ($parts as $part) {
            $part = (string) $part;
            if (trim($part) === '') {
                throw new InvalidArgumentException("Reference parts must not be empty for scope '{$scope}'.");
            }
            $cleaned[] = $part;
        }

        return new self($scope.':'.implode(':', $cleaned));
    }

    /**
     * Reconstruct an already-formatted reference (e.g. when reading from the DB).
     */
    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Reference value must not be empty.');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function assertValidScope(string $scope): void
    {
        if (preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
            throw new InvalidArgumentException(
                "Reference scope '{$scope}' must be dot-separated lowercase identifiers (e.g. 'order.paid')."
            );
        }
    }
}
