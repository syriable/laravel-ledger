<?php

declare(strict_types=1);

namespace Syriable\Ledger\Tests\Fixtures;

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Deliberately imbalanced — debit 100, credit 50. Used to verify the
 * BalancedTransactionValidator catches imbalance at the recorder boundary.
 */
final class ImbalancedPosting extends Posting
{
    public function __construct(
        public readonly string $ledgerSlug,
        public readonly Account $a,
        public readonly Account $b,
    ) {}

    public function ledger(): string
    {
        return $this->ledgerSlug;
    }

    public function reference(): Reference
    {
        return Reference::for('test.imbalanced', '1');
    }

    public function currency(): string
    {
        return 'USD';
    }

    /**
     * @return list<EntryDraft>
     */
    public function entries(): array
    {
        return [
            new EntryDraft($this->a->id, EntryDirection::Debit, Money::of(100, 'USD')),
            new EntryDraft($this->b->id, EntryDirection::Credit, Money::of(50, 'USD')),
        ];
    }
}
