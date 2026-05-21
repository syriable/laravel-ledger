<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Events\TransactionPosted;
use Syriable\Ledger\Events\TransactionReversed;
use Syriable\Ledger\Exceptions\AccountArchivedException;
use Syriable\Ledger\Exceptions\ImbalancedTransactionException;
use Syriable\Ledger\Exceptions\ReversalNotAllowedException;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Entry;
use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\Tests\Fixtures\ImbalancedPosting;
use Syriable\Ledger\Tests\Fixtures\SimpleTransferPosting;
use Syriable\Ledger\ValueObjects\Money;

beforeEach(function (): void {
    $this->ledger = Ledger::createLedger(slug: 'test-main', currency: 'USD', name: 'Test Main');

    $this->cash = Ledger::for('test-main')->openAccount(
        code: 'platform.cash.usd',
        type: AccountType::Asset,
        currency: 'USD',
    );

    $this->reserve = Ledger::for('test-main')->openAccount(
        code: 'platform.reserve.usd',
        type: AccountType::Asset,
        currency: 'USD',
    );
});

it('happy path: posts a balanced transaction and projects balances', function (): void {
    Event::fake([TransactionPosted::class]);

    $result = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'test-main',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(1_000, 'USD'),
        referenceId: 'tx-1',
    ));

    expect($result->wasReplayed)->toBeFalse()
        ->and($result->transaction)->toBeBalanced();

    // Cash (Asset, debit-normal): credited 1000 → signed balance = -1000.
    // Reserve (Asset, debit-normal): debited 1000 → signed balance = +1000.
    expect($this->cash->fresh()->balance())->toBe(-1_000);
    expect($this->reserve->fresh()->balance())->toBe(1_000);

    expect($this->ledger)->toHaveZeroSum()
        ->and($this->ledger)->toHaveBalancesEqualEntries();

    Event::assertDispatched(TransactionPosted::class, fn (TransactionPosted $e): bool => $e->transaction->id === $result->transaction->id
    );
});

it('replay: same reference returns the original transaction with wasReplayed=true', function (): void {
    $first = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'test-main',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(500, 'USD'),
        referenceId: 'replay-1',
    ));

    $second = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'test-main',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(500, 'USD'),
        referenceId: 'replay-1',
    ));

    expect($first->wasReplayed)->toBeFalse()
        ->and($second->wasReplayed)->toBeTrue()
        ->and($second->transaction->id)->toBe($first->transaction->id)
        ->and(Transaction::query()->count())->toBe(1)
        ->and(Entry::query()->count())->toBe(2);
});

it('rejects an imbalanced posting', function (): void {
    Ledger::post(new ImbalancedPosting(
        ledgerSlug: 'test-main',
        a: $this->cash,
        b: $this->reserve,
    ));
})->throws(ImbalancedTransactionException::class);

it('rejects a posting that touches an archived account', function (): void {
    Ledger::archiveAccount($this->cash);

    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'test-main',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(100, 'USD'),
        referenceId: 'against-archived',
    ));
})->throws(AccountArchivedException::class);

it('reverses a transaction and leaves balances at their pre-posting state', function (): void {
    $posted = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'test-main',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(2_000, 'USD'),
        referenceId: 'reversal-test',
    ));

    Event::fake([TransactionReversed::class]);

    $reversal = Ledger::reverse($posted->transaction, reason: 'test reversal');

    expect($reversal->transaction->reverses_transaction_id)->toBe($posted->transaction->id)
        ->and($reversal->transaction)->toBeBalanced()
        ->and($posted->transaction->fresh()->isReversed())->toBeTrue();

    expect($this->ledger)->toHaveZeroSum()
        ->and($this->ledger)->toHaveBalancesEqualEntries();

    Event::assertDispatched(TransactionReversed::class);
});

it('refuses to reverse the same transaction twice', function (): void {
    $posted = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'test-main',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(700, 'USD'),
        referenceId: 'double-reverse',
    ));

    Ledger::reverse($posted->transaction);
    Ledger::reverse($posted->transaction->fresh());
})->throws(ReversalNotAllowedException::class);

it('refuses to reverse a reversal', function (): void {
    $posted = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'test-main',
        from: $this->cash,
        to: $this->reserve,
        amount: Money::of(300, 'USD'),
        referenceId: 'rev-of-rev',
    ));

    $reversal = Ledger::reverse($posted->transaction);

    Ledger::reverse($reversal->transaction);
})->throws(ReversalNotAllowedException::class);

it('maintains ledger zero-sum across many random postings', function (): void {
    for ($i = 1; $i <= 25; $i++) {
        $amount = random_int(1, 10_000);

        Ledger::post(new SimpleTransferPosting(
            ledgerSlug: 'test-main',
            from: random_int(0, 1) === 0 ? $this->cash : $this->reserve,
            to: random_int(0, 1) === 0 ? $this->reserve : $this->cash,
            amount: Money::of($amount, 'USD'),
            referenceId: "random-{$i}",
        ));
    }

    expect($this->ledger)->toHaveZeroSum()
        ->and($this->ledger)->toHaveBalancesEqualEntries();
});
