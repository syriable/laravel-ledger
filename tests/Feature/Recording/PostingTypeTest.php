<?php

declare(strict_types=1);

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\Tests\Fixtures\SimpleTransferPosting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

beforeEach(function (): void {
    Ledger::createLedger(slug: 'type-test', currency: 'USD');
    $scope = Ledger::for('type-test');
    $this->a = $scope->openAccount(code: 'type.a.usd', type: AccountType::Asset, currency: 'USD');
    $this->b = $scope->openAccount(code: 'type.b.usd', type: AccountType::Asset, currency: 'USD');
});

it('falls back to FQCN posting_type when no type() is declared', function (): void {
    $result = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'type-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(100, 'USD'),
        referenceId: 'fqcn-default',
    ));

    expect($result->transaction->posting_type)->toBe(SimpleTransferPosting::class);
});

it('uses the stable token declared by type() when overridden', function (): void {
    $posting = new class($this->a, $this->b) extends Posting
    {
        public function __construct(
            private readonly Account $from,
            private readonly Account $to,
        ) {}

        public function ledger(): string
        {
            return 'type-test';
        }

        public function reference(): Reference
        {
            return Reference::for('order.paid', 'stable-1');
        }

        public function currency(): string
        {
            return 'USD';
        }

        public function type(): string
        {
            return 'order.paid';
        }

        public function entries(): array
        {
            return [
                EntryDraft::debit($this->to, Money::of(200, 'USD')),
                EntryDraft::credit($this->from, Money::of(200, 'USD')),
            ];
        }
    };

    $result = Ledger::post($posting);

    expect($result->transaction->posting_type)->toBe('order.paid');
});

it('marks reversals with the ledger.reversal stable type', function (): void {
    $posted = Ledger::post(new SimpleTransferPosting(
        ledgerSlug: 'type-test',
        from: $this->a,
        to: $this->b,
        amount: Money::of(50, 'USD'),
        referenceId: 'to-reverse',
    ));

    $reversal = Ledger::reverse($posted->transaction);

    expect($reversal->transaction->posting_type)->toBe('ledger.reversal');
});
