<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Exceptions\LedgerException;
use Syriable\Ledger\Exceptions\LedgerWriteFailedException;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\LedgerManager;
use Syriable\Ledger\Recording\BalanceProjector;
use Syriable\Ledger\Recording\TransactionRecorder;
use Syriable\Ledger\Tests\Fixtures\SimpleTransferPosting;
use Syriable\Ledger\ValueObjects\Money;

beforeEach(function (): void {
    Ledger::createLedger(slug: 'wrap-test', currency: 'USD');
    $scope = Ledger::for('wrap-test');
    $this->a = $scope->openAccount(code: 'a.usd', type: AccountType::Asset, currency: 'USD');
    $this->b = $scope->openAccount(code: 'b.usd', type: AccountType::Asset, currency: 'USD');

    // The Ledger facade caches the resolved LedgerManager. Each test below
    // re-binds the BalanceProjector to inject a failure, so clear every cached
    // resolution that depends on it.
    Ledger::clearResolvedInstance(LedgerManager::class);
    $this->app->forgetInstance(LedgerManager::class);
    $this->app->forgetInstance(TransactionRecorder::class);
});

it('wraps a non-classifiable QueryException as LedgerWriteFailedException', function (): void {
    $this->app->bind(BalanceProjector::class, fn () => new class implements BalanceProjector
    {
        public function apply(array $entries, Collection $accounts): void
        {
            throw new QueryException(
                'testing',
                'INSERT INTO balances ...',
                [],
                new RuntimeException('synthetic non-unique driver failure'),
            );
        }
    });

    expect(fn () => Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'wrap-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(100, 'USD'),
        referenceId: 'wrap-1',
    )))->toThrow(LedgerWriteFailedException::class);
});

it('wraps a runtime failure escaping the write path as LedgerWriteFailedException', function (): void {
    $this->app->bind(BalanceProjector::class, fn () => new class implements BalanceProjector
    {
        public function apply(array $entries, Collection $accounts): void
        {
            throw new RuntimeException('synthetic projector explosion');
        }
    });

    expect(fn () => Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'wrap-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(100, 'USD'),
        referenceId: 'wrap-2',
    )))->toThrow(LedgerWriteFailedException::class);
});

it('lets consumers catch every package write failure via LedgerException', function (): void {
    $this->app->bind(BalanceProjector::class, fn () => new class implements BalanceProjector
    {
        public function apply(array $entries, Collection $accounts): void
        {
            throw new RuntimeException('boom');
        }
    });

    try {
        Ledger::post(new SimpleTransferPosting(
            ledgerSlug: 'wrap-test',
            from: $this->a,
            to: $this->b,
            amount: Money::of(100, 'USD'),
            referenceId: 'wrap-3',
        ));
        $this->fail('Expected LedgerException');
    } catch (LedgerException $e) {
        expect($e)->toBeInstanceOf(LedgerWriteFailedException::class)
            ->and($e->getPrevious())->not->toBeNull();
    }
});
