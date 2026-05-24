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

it('rejects parts that contain a colon to prevent silent collisions', function (): void {
    // Without this rejection, ('order.paid', '1:2') and ('order.paid', '1', '2')
    // both render to "order.paid:1:2" and would silently share an idempotency
    // slot.
    Reference::for('order.paid', '1:2');
})->throws(InvalidArgumentException::class);

it('does not collide on parts that differ only by where the colons are', function (): void {
    $a = Reference::for('order.paid', '1', '2');
    expect((string) $a)->toBe('order.paid:1:2');

    // The colon-bearing alternative is rejected; that asymmetry is what
    // guarantees the rendered string maps back to one unique tuple.
    expect(fn () => Reference::for('order.paid', '1:2'))
        ->toThrow(InvalidArgumentException::class);
});
