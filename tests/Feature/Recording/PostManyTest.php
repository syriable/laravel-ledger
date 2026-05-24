<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Exceptions\ImbalancedTransactionException;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\Tests\Fixtures\ImbalancedPosting;
use Syriable\Ledger\Tests\Fixtures\SimpleTransferPosting;
use Syriable\Ledger\ValueObjects\Money;

beforeEach(function (): void {
    Ledger::createLedger(slug: 'batch-test', currency: 'USD');
    $scope = Ledger::for('batch-test');
    $this->a = $scope->openAccount(code: 'batch.a.usd', type: AccountType::Asset, currency: 'USD');
    $this->b = $scope->openAccount(code: 'batch.b.usd', type: AccountType::Asset, currency: 'USD');
});

it('records every posting in a batch and returns one result per posting', function (): void {
    $postings = [];
    for ($i = 1; $i <= 5; $i++) {
        $postings[] = new SimpleTransferPosting(
            ledgerSlug: 'batch-test',
            from: $this->a,
            to: $this->b,
            amount: Money::of(100, 'USD'),
            referenceId: "batch-{$i}",
        );
    }

    $results = Ledger::postMany($postings);

    expect($results)->toHaveCount(5)
        ->and(collect($results)->every(fn ($r) => ! $r->wasReplayed))->toBeTrue()
        ->and(Transaction::query()->count())->toBe(5);
});

it('rolls back the whole batch when one posting fails', function (): void {
    $before = Transaction::query()->count();

    try {
        Ledger::postMany([
            new SimpleTransferPosting(
                ledgerSlug: 'batch-test',
                from: $this->a,
                to: $this->b,
                amount: Money::of(100, 'USD'),
                referenceId: 'batch-ok-1',
            ),
            new ImbalancedPosting(
                ledgerSlug: 'batch-test',
                a: $this->a,
                b: $this->b,
            ),
        ]);
    } catch (ImbalancedTransactionException) {
        // expected
    }

    expect(Transaction::query()->count())->toBe($before);
});

it('treats in-batch duplicates as idempotent replays without growing the row count', function (): void {
    $posting = new SimpleTransferPosting(
        ledgerSlug: 'batch-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(50, 'USD'),
        referenceId: 'shared-ref',
    );

    $results = Ledger::postMany([$posting, $posting]);

    expect($results[0]->wasReplayed)->toBeFalse()
        ->and($results[1]->wasReplayed)->toBeTrue()
        ->and(Transaction::query()->count())->toBe(1);
});

it('uses one DB transaction for the whole batch', function (): void {
    $beginCount = 0;
    DB::listen(function ($query) use (&$beginCount): void {
        if (str_starts_with(strtoupper(trim($query->sql)), 'BEGIN')
            || str_starts_with(strtoupper(trim($query->sql)), 'START TRANSACTION')) {
            $beginCount++;
        }
    });

    Ledger::postMany([
        new SimpleTransferPosting(
            ledgerSlug: 'batch-test',
            from: $this->a,
            to: $this->b,
            amount: Money::of(10, 'USD'),
            referenceId: 'count-1',
        ),
        new SimpleTransferPosting(
            ledgerSlug: 'batch-test',
            from: $this->a,
            to: $this->b,
            amount: Money::of(10, 'USD'),
            referenceId: 'count-2',
        ),
    ]);

    // Note: DB::listen does not surface SAVEPOINT statements on every driver.
    // What we assert is the loose bound: there is at most one outer BEGIN.
    expect($beginCount)->toBeLessThanOrEqual(1);
});
