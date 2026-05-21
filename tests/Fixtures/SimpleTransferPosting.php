<?php

declare(strict_types=1);

namespace Syriable\Ledger\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\Recording\Clock;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * A minimal Posting fixture used across feature tests: debit one account,
 * credit another, both for the same amount.
 *
 * An explicit $postedAt may be supplied so timing-sensitive tests
 * (balanceAsOf, historical queries) are deterministic rather than
 * relying on wall-clock gaps.
 */
final class SimpleTransferPosting extends Posting
{
    public function __construct(
        public readonly string $ledgerSlug,
        public readonly Account $from,
        public readonly Account $to,
        public readonly Money $amount,
        public readonly string $referenceId,
        public readonly ?CarbonImmutable $postedAtOverride = null,
    ) {}

    public function ledger(): string
    {
        return $this->ledgerSlug;
    }

    public function reference(): Reference
    {
        return Reference::for('test.transfer', $this->referenceId);
    }

    public function currency(): string
    {
        return $this->amount->currency;
    }

    public function description(): ?string
    {
        return "Transfer {$this->amount} from {$this->from->code} to {$this->to->code}";
    }

    public function postedAt(Clock $clock): CarbonImmutable
    {
        return $this->postedAtOverride ?? $clock->now();
    }

    /**
     * @return list<EntryDraft>
     */
    public function entries(): array
    {
        // Debit `to` increases an asset-style account; credit `from` decreases it.
        // For our test scenarios both accounts are Assets so this transfers value.
        return [
            EntryDraft::debit($this->to, $this->amount),
            EntryDraft::credit($this->from, $this->amount),
        ];
    }
}
