<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Exceptions\PostedAtOutOfBoundsException;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Tests\Fixtures\SimpleTransferPosting;
use Syriable\Ledger\ValueObjects\Money;

beforeEach(function (): void {
    Ledger::createLedger(slug: 'posted-at-test', currency: 'USD');
    $scope = Ledger::for('posted-at-test');
    $this->a = $scope->openAccount(code: 'a.usd', type: AccountType::Asset, currency: 'USD');
    $this->b = $scope->openAccount(code: 'b.usd', type: AccountType::Asset, currency: 'USD');

    CarbonImmutable::setTestNow('2026-05-24 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('accepts postings within the default clock skew window', function (): void {
    // 60 seconds in the future — comfortably inside the 300s default skew.
    $result = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'posted-at-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(100, 'USD'),
        referenceId: 'in-window',
        postedAtOverride: CarbonImmutable::parse('2026-05-24 10:01:00'),
    ));

    expect($result->wasReplayed)->toBeFalse();
});

it('rejects postings beyond the configured future window', function (): void {
    config()->set('ledger.max_clock_skew_seconds', 10);

    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'posted-at-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(100, 'USD'),
        referenceId: 'future',
        postedAtOverride: CarbonImmutable::parse('2026-05-24 10:01:00'), // 60s ahead, > 10s budget
    ));
})->throws(PostedAtOutOfBoundsException::class);

it('rejects postings before the configured historical lower bound', function (): void {
    config()->set('ledger.historical_lower_bound', '2026-01-01T00:00:00Z');

    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'posted-at-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(100, 'USD'),
        referenceId: 'too-old',
        postedAtOverride: CarbonImmutable::parse('2025-12-31 23:59:59'),
    ));
})->throws(PostedAtOutOfBoundsException::class);

it('accepts a posting equal to the historical lower bound', function (): void {
    config()->set('ledger.historical_lower_bound', '2026-01-01T00:00:00Z');

    $result = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'posted-at-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(100, 'USD'),
        referenceId: 'on-bound',
        postedAtOverride: CarbonImmutable::parse('2026-01-01T00:00:00Z'),
    ));

    expect($result->wasReplayed)->toBeFalse();
});

it('accepts a callable historical lower bound', function (): void {
    config()->set('ledger.historical_lower_bound', fn () => CarbonImmutable::parse('2026-05-01'));

    Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'posted-at-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(100, 'USD'),
        referenceId: 'too-old-cb',
        postedAtOverride: CarbonImmutable::parse('2026-04-30'),
    ));
})->throws(PostedAtOutOfBoundsException::class);
