<?php

declare(strict_types=1);

namespace Syriable\Ledger\Simulation\Postings;

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Simulation posting — an order completes; the seller's escrowed funds
 * become available for payout. Both accounts are Liabilities: escrow
 * decreases (debit), available increases (credit).
 *
 * @internal
 */
final class SimOrderCompletedPosting extends Posting
{
    public function __construct(
        private readonly string $orderId,
        private readonly string $ledgerSlug,
        private readonly Account $sellerEscrow,
        private readonly Account $sellerAvailable,
        private readonly Money $sellerNet,
    ) {}

    public function ledger(): string
    {
        return $this->ledgerSlug;
    }

    public function currency(): string
    {
        return $this->sellerNet->currency;
    }

    public function reference(): Reference
    {
        return Reference::for('sim.order.completed', $this->orderId);
    }

    public function description(): string
    {
        return "Simulated order {$this->orderId} completed";
    }

    /**
     * @return list<EntryDraft>
     */
    public function entries(): array
    {
        return [
            EntryDraft::debit($this->sellerEscrow, $this->sellerNet),
            EntryDraft::credit($this->sellerAvailable, $this->sellerNet),
        ];
    }
}
