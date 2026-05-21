<?php

declare(strict_types=1);

use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Enums\NormalBalance;
use Syriable\Ledger\ValueObjects\AccountCode;

it('accepts a dotted lowercase code', function (): void {
    $code = new AccountCode('platform.cash.usd');

    expect($code->value)->toBe('platform.cash.usd');
});

it('refuses codes with uppercase or invalid characters', function (string $bad): void {
    new AccountCode($bad);
})->with([
    'Platform.Cash.USD',
    'platform_cash',
    '',
    '.leading',
    'trailing.',
    'has space',
])->throws(InvalidArgumentException::class);

it('maps account types to their normal balance', function (): void {
    expect(AccountType::Asset->normalBalance())->toBe(NormalBalance::Debit)
        ->and(AccountType::Expense->normalBalance())->toBe(NormalBalance::Debit)
        ->and(AccountType::Liability->normalBalance())->toBe(NormalBalance::Credit)
        ->and(AccountType::Equity->normalBalance())->toBe(NormalBalance::Credit)
        ->and(AccountType::Revenue->normalBalance())->toBe(NormalBalance::Credit);
});

it('returns the opposite of an entry direction', function (): void {
    expect(EntryDirection::Debit->opposite())->toBe(EntryDirection::Credit)
        ->and(EntryDirection::Credit->opposite())->toBe(EntryDirection::Debit);
});
