<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Tests\Fixtures\SimpleTransferPosting;
use Syriable\Ledger\ValueObjects\Money;

beforeEach(function (): void {
    Ledger::createLedger(slug: 'scope-test', currency: 'USD');
    $scope = Ledger::for('scope-test');
    $accounts = [];
    for ($i = 0; $i < 5; $i++) {
        $accounts[] = $scope->openAccount(
            code: "scope.{$i}.usd",
            type: AccountType::Asset,
            currency: 'USD',
        );
    }
    $this->accounts = $accounts;

    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'scope-test',
        from: $accounts[0],
        to: $accounts[1],
        amount: Money::of(500, 'USD'),
        referenceId: 'scope-warmup',
    ));
});

it('lazy-loads balanceProjection per call without the scope (baseline)', function (): void {
    $accounts = Account::query()->where('ledger_id', $this->accounts[0]->ledger_id)->get();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach ($accounts as $a) {
        $a->balance();
    }

    // One query per account without the scope.
    expect($queries)->toBeGreaterThanOrEqual($accounts->count());
});

it('issues exactly one extra query with the withBalance scope', function (): void {
    $accounts = Account::query()
        ->withBalance()
        ->where('ledger_id', $this->accounts[0]->ledger_id)
        ->get();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach ($accounts as $a) {
        $a->balance();
    }

    // Projection was already eager-loaded by withBalance(); subsequent
    // balance() calls should issue zero further queries.
    expect($queries)->toBe(0);
});
