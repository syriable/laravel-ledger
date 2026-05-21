<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Balance;
use Syriable\Ledger\Tests\Fixtures\SimpleTransferPosting;
use Syriable\Ledger\ValueObjects\Money;

beforeEach(function (): void {
    $this->ledger = Ledger::createLedger(slug: 'history-test', currency: 'USD');
    $this->cash = Ledger::for('history-test')->openAccount(
        code: 'platform.cash.usd',
        type: AccountType::Asset,
        currency: 'USD',
    );
    $this->reserve = Ledger::for('history-test')->openAccount(
        code: 'platform.reserve.usd',
        type: AccountType::Asset,
        currency: 'USD',
    );
});

it('balanceAsOf includes entries posted at or before the cutoff', function (): void {
    // Two postings with explicit, well-separated business times so the test
    // is deterministic and does not depend on wall-clock timing.
    $firstPostedAt = CarbonImmutable::parse('2026-05-01 10:00:00');
    $secondPostedAt = CarbonImmutable::parse('2026-05-02 10:00:00');
    $cutoff = CarbonImmutable::parse('2026-05-01 12:00:00'); // between the two

    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'history-test',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(500, 'USD'),
        referenceId: 'history-1',
        postedAtOverride: $firstPostedAt,
    ));

    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'history-test',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(300, 'USD'),
        referenceId: 'history-2',
        postedAtOverride: $secondPostedAt,
    ));

    // At the cutoff: only the first posting counts → reserve +500.
    expect($this->reserve->fresh()->balanceAsOf($cutoff))->toBe(500);

    // After both: reserve +800.
    expect($this->reserve->fresh()->balanceAsOf($secondPostedAt))->toBe(800);
});

it('rebuild-balances reproduces the projection exactly when no drift exists', function (): void {
    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'history-test',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(2_500, 'USD'),
        referenceId: 'rebuild-1',
    ));

    $cashBefore = $this->cash->fresh()->balance();
    $reserveBefore = $this->reserve->fresh()->balance();

    Artisan::call('ledger:rebuild-balances', ['--ledger' => 'history-test']);

    expect($this->cash->fresh()->balance())->toBe($cashBefore)
        ->and($this->reserve->fresh()->balance())->toBe($reserveBefore);

    expect($this->ledger)->toHaveBalancesEqualEntries();
});

it('rebuild-balances heals drift from a corrupted projection', function (): void {
    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'history-test',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(1_000, 'USD'),
        referenceId: 'drift-1',
    ));

    // Corrupt the projection directly (only possible via raw DB, which we
    // do here to simulate disaster recovery).
    Balance::openRecorderWindow();
    try {
        DB::table((new Balance)->getTable())
            ->where('account_id', $this->reserve->id)
            ->update(['balance' => 999_999]);
    } finally {
        Balance::closeRecorderWindow();
    }

    expect($this->reserve->fresh()->balance())->toBe(999_999); // drifted

    Artisan::call('ledger:rebuild-balances', ['--ledger' => 'history-test']);

    expect($this->reserve->fresh()->balance())->toBe(1_000); // healed
    expect($this->ledger)->toHaveBalancesEqualEntries();
});

it('ledger:verify exits 0 when invariants hold', function (): void {
    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'history-test',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(100, 'USD'),
        referenceId: 'verify-clean',
    ));

    $exit = Artisan::call('ledger:verify');

    expect($exit)->toBe(0);
});

it('ledger:verify exits non-zero when the projection is drifted', function (): void {
    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'history-test',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(100, 'USD'),
        referenceId: 'verify-drift',
    ));

    Balance::openRecorderWindow();
    try {
        DB::table((new Balance)->getTable())
            ->where('account_id', $this->reserve->id)
            ->update(['balance' => 50_000]);
    } finally {
        Balance::closeRecorderWindow();
    }

    $exit = Artisan::call('ledger:verify');

    expect($exit)->toBe(1);
});
