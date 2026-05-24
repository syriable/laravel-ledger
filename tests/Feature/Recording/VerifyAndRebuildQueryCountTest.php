<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Tests\Fixtures\SimpleTransferPosting;
use Syriable\Ledger\ValueObjects\Money;

beforeEach(function (): void {
    Ledger::createLedger(slug: 'scale-test', currency: 'USD');
    $scope = Ledger::for('scale-test');

    // Open ten accounts and post one transfer per pair so we have a
    // non-trivial number of accounts and transactions to verify.
    $this->accounts = collect();
    for ($i = 0; $i < 10; $i++) {
        $this->accounts->push($scope->openAccount(
            code: "scale.acc.{$i}.usd",
            type: AccountType::Asset,
            currency: 'USD',
        ));
    }

    for ($i = 0; $i < 9; $i++) {
        Ledger::post(new SimpleTransferPosting(
            ledgerSlug: 'scale-test',
            from: $this->accounts[$i],
            to: $this->accounts[$i + 1],
            amount: Money::of(100, 'USD'),
            referenceId: "scale-{$i}",
        ));
    }
});

it('verifies a ledger in a bounded number of queries regardless of size', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $exit = Artisan::call('ledger:verify', ['--ledger' => 'scale-test']);

    expect($exit)->toBe(0);

    // Before this change, verify issued ~2 SUM queries per transaction
    // + 2 per account = O(N). With set-based SQL the budget is small and
    // independent of N: one ledger lookup, three aggregations, plus a
    // handful of framework lookups. 25 is a generous ceiling that still
    // catches any return-to-N+1 regression.
    expect($queries)->toBeLessThan(25, "verify ran in {$queries} queries; expected an O(1)-per-ledger budget");
});

it('rebuilds balances in a bounded number of queries regardless of size', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $exit = Artisan::call('ledger:rebuild-balances', ['--ledger' => 'scale-test']);

    expect($exit)->toBe(0);

    // The new path is: lookup ledger, BEGIN, DELETE old balances,
    // INSERT...SELECT, COMMIT. Total stays well below the old per-account
    // chunked path that issued 2 SUM queries per account.
    expect($queries)->toBeLessThan(25, "rebuild ran in {$queries} queries; expected an O(1)-per-ledger budget");
});
