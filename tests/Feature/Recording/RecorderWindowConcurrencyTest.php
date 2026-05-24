<?php

declare(strict_types=1);

use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Exceptions\DirectWriteForbiddenException;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Account;

beforeEach(function (): void {
    Ledger::createLedger(slug: 'fiber-test', currency: 'USD');
    $this->account = Ledger::for('fiber-test')->openAccount(
        code: 'concurrency.cash.usd',
        type: AccountType::Asset,
        currency: 'USD',
    );
});

it('keeps recorder windows isolated between fibers', function (): void {
    // Fiber A opens a window and suspends mid-flight.
    // While A is suspended, fiber B must still see a CLOSED window and
    // its save() must throw — otherwise A's open window would leak to B
    // and bypass the safety net (the Swoole/RoadRunner failure mode).
    $bSawClosed = null;
    $bThrew = false;

    $fiberA = new Fiber(function () {
        Account::openRecorderWindow();
        try {
            Fiber::suspend(); // hand control to the test thread
        } finally {
            Account::closeRecorderWindow();
        }
    });

    $fiberB = new Fiber(function () use (&$bSawClosed, &$bThrew): void {
        $bSawClosed = ! Account::isRecorderWindowOpen();
        try {
            /** @var Account $account */
            $account = Account::query()->first();
            $account->name = 'tampered-from-fiber-b';
            $account->save();
        } catch (DirectWriteForbiddenException) {
            $bThrew = true;
        }
    });

    $fiberA->start();

    expect(Account::isRecorderWindowOpen())->toBeFalse(
        'main fiber must not see fiber A\'s open window'
    );

    $fiberB->start();

    expect($bSawClosed)->toBeTrue('fiber B saw an open window opened by fiber A')
        ->and($bThrew)->toBeTrue('fiber B should have been refused');

    $fiberA->resume();
});

it('still supports nested recorder windows in the main fiber', function (): void {
    expect(Account::isRecorderWindowOpen())->toBeFalse();

    Account::openRecorderWindow();
    expect(Account::isRecorderWindowOpen())->toBeTrue();

    Account::openRecorderWindow();
    expect(Account::isRecorderWindowOpen())->toBeTrue();

    Account::closeRecorderWindow();
    expect(Account::isRecorderWindowOpen())->toBeTrue('still nested once');

    Account::closeRecorderWindow();
    expect(Account::isRecorderWindowOpen())->toBeFalse();
});

it('does not underflow if closeRecorderWindow is called without an open window', function (): void {
    // Defensive: a stray close must not flip into a "permanently open" state.
    Account::closeRecorderWindow();
    Account::closeRecorderWindow();

    expect(Account::isRecorderWindowOpen())->toBeFalse();
});
