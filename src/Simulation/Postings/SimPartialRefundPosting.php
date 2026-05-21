<?php

declare(strict_types=1);

namespace Syriable\Ledger\Simulation\Postings;

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Simulation posting — a partial refund on a completed order.
 *
 * A partial refund is a new economic event, not a reversal. The refund is
 * taken back out of the seller's escrow (Liability, debit) and the
 * commission portion is clawed back from revenue (Revenue, debit); cash
 * (Asset) decreases (credit) to fund the total.
 *
 * @internal
 */
final class SimPartialRefundPosting extends Posting
{
    public function __construct(
        private readonly string $refundId,
        private readonly string $ledgerSlug,
        private readonly Account $sellerEscrow,
        private readonly Account $commissionRevenue,
        private readonly Account $platformCash,
        private readonly Money $refundAmount,
        private readonly Money $commissionClaw,
    ) {}

    public function ledger(): string
    {
        return $this->ledgerSlug;
    }

    public function currency(): string
    {
        return $this->refundAmount->currency;
    }

    public function reference(): Reference
    {
        return Reference::for('sim.order.refunded.partial', $this->refundId);
    }

    public function description(): string
    {
        return "Simulated partial refund {$this->refundId}";
    }

    /**
     * @return list<EntryDraft>
     */
    public function entries(): array
    {
        return [
            EntryDraft::debit($this->sellerEscrow, $this->refundAmount),
            EntryDraft::debit($this->commissionRevenue, $this->commissionClaw),
            EntryDraft::credit($this->platformCash, $this->refundAmount->plus($this->commissionClaw)),
        ];
    }
}
