<?php

declare(strict_types=1);

use Syriable\Ledger\ValueObjects\Reference;

it('builds a dot-scoped reference from a scope and parts', function (): void {
    $reference = Reference::for('order.paid', 1234);

    expect((string) $reference)->toBe('order.paid:1234');
});

it('joins multiple parts with colons', function (): void {
    $reference = Reference::for('payout.settled', 7, 3);

    expect((string) $reference)->toBe('payout.settled:7:3');
});

it('refuses an undotted scope', function (): void {
    Reference::for('paid', 1);
})->throws(InvalidArgumentException::class);

it('refuses an empty scope', function (): void {
    Reference::for('', 1);
})->throws(InvalidArgumentException::class);

it('refuses uppercase characters in the scope', function (): void {
    Reference::for('Order.Paid', 1);
})->throws(InvalidArgumentException::class);

it('refuses references with no parts', function (): void {
    Reference::for('order.paid');
})->throws(InvalidArgumentException::class);

it('refuses empty or whitespace parts', function (): void {
    Reference::for('order.paid', '');
})->throws(InvalidArgumentException::class);

it('treats equal references as equal', function (): void {
    expect(
        Reference::for('order.paid', 1)->equals(Reference::for('order.paid', 1))
    )->toBeTrue();
});

it('hydrates from a stored string', function (): void {
    $reference = Reference::fromString('order.paid:42');

    expect((string) $reference)->toBe('order.paid:42');
});
