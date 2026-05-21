<?php

declare(strict_types=1);

use Syriable\Ledger\Exceptions\InvalidMoneyException;
use Syriable\Ledger\ValueObjects\Money;

it('constructs from non-negative minor units', function (): void {
    $money = Money::of(1234, 'USD');

    expect($money->minorUnits)->toBe(1234)
        ->and($money->currency)->toBe('USD');
});

it('refuses negative minor units', function (): void {
    Money::of(-1, 'USD');
})->throws(InvalidMoneyException::class);

it('refuses lowercase or malformed currency codes', function (string $bad): void {
    Money::of(100, $bad);
})->with([
    'usd',
    'US',
    'US1',
    'USDE',
    '',
    ' ',
])->throws(InvalidMoneyException::class);

it('produces zero in a currency', function (): void {
    $zero = Money::zero('EUR');

    expect($zero->isZero())->toBeTrue()
        ->and($zero->isPositive())->toBeFalse()
        ->and($zero->currency)->toBe('EUR');
});

it('adds two Money values of the same currency', function (): void {
    $sum = Money::of(100, 'USD')->plus(Money::of(50, 'USD'));

    expect($sum->minorUnits)->toBe(150)
        ->and($sum->currency)->toBe('USD');
});

it('refuses to add across currencies', function (): void {
    Money::of(100, 'USD')->plus(Money::of(50, 'EUR'));
})->throws(InvalidMoneyException::class);

it('subtracts when the result is non-negative', function (): void {
    $diff = Money::of(100, 'USD')->minus(Money::of(40, 'USD'));

    expect($diff->minorUnits)->toBe(60);
});

it('refuses subtraction that would underflow below zero', function (): void {
    Money::of(40, 'USD')->minus(Money::of(100, 'USD'));
})->throws(InvalidMoneyException::class);

it('answers same-currency comparisons', function (): void {
    $a = Money::of(1, 'USD');
    $b = Money::of(2, 'USD');
    $c = Money::of(1, 'EUR');

    expect($a->sameCurrencyAs($b))->toBeTrue()
        ->and($a->sameCurrencyAs($c))->toBeFalse();
});

it('treats equal money as equal', function (): void {
    expect(Money::of(100, 'USD')->equals(Money::of(100, 'USD')))->toBeTrue()
        ->and(Money::of(100, 'USD')->equals(Money::of(101, 'USD')))->toBeFalse()
        ->and(Money::of(100, 'USD')->equals(Money::of(100, 'EUR')))->toBeFalse();
});

it('preserves total across plus/minus', function (): void {
    $original = Money::of(1000, 'USD');
    $added = $original->plus(Money::of(250, 'USD'));
    $back = $added->minus(Money::of(250, 'USD'));

    expect($back->equals($original))->toBeTrue();
});
