<?php

declare(strict_types=1);

namespace Syriable\Ledger\Simulation\Postings;

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Simulation posting — a buyer pays for an order.
 *
 * Cash enters the platform (Asset, debit). The seller's net is held in
 * escrow (Liability, credit). The platform's commission is recognised
 * revenue (Revenue, credit).
 *
 * This class lives in the package only to support `ledger:simulate`.
 * Real applications write their own postings — see the docs cookbook.
 *
 * @internal
 */
final class SimOrderPaidPosting extends Posting
{
    public function __construct(
        private readonly string $orderId,
        private readonly string $ledgerSlug,
        private readonly Account $cash,
        private readonly Account $sellerEscrow,
        private readonly Account $commissionRevenue,
        private readonly Money $total,
        private readonly Money $sellerNet,
        private readonly Money $commission,
    ) {}

    public function ledger(): string
    {
        return $this->ledgerSlug;
    }

    public function currency(): string
    {
        return $this->total->currency;
    }

    public function reference(): Reference
    {
        return Reference::for('sim.order.paid', $this->orderId);
    }

    public function description(): string
    {
        return "Simulated order {$this->orderId} paid";
    }

    /**
     * @return list<EntryDraft>
     */
    public function entries(): array
    {
        return [
            EntryDraft::debit($this->cash, $this->total),
            EntryDraft::credit($this->sellerEscrow, $this->sellerNet),
            EntryDraft::credit($this->commissionRevenue, $this->commission),
        ];
    }
}
