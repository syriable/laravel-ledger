<?php

declare(strict_types=1);

namespace Syriable\Ledger\Simulation\Postings;

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Simulation posting — a seller is paid out.
 *
 * The seller's available balance (Liability) decreases (debit); the
 * platform's cash (Asset) decreases (credit) as real money leaves.
 *
 * The simulator models payout as a single settled transaction rather than
 * the initiated/settled pair used in the documentation cookbook — this
 * keeps the simulation's account count down while still exercising a
 * realistic Liability-to-Asset movement.
 *
 * @internal
 */
final class SimPayoutPosting extends Posting
{
    public function __construct(
        private readonly string $payoutId,
        private readonly string $ledgerSlug,
        private readonly Account $sellerAvailable,
        private readonly Account $platformCash,
        private readonly Money $amount,
    ) {}

    public function ledger(): string
    {
        return $this->ledgerSlug;
    }

    public function currency(): string
    {
        return $this->amount->currency;
    }

    public function reference(): Reference
    {
        return Reference::for('sim.payout', $this->payoutId);
    }

    public function description(): string
    {
        return "Simulated payout {$this->payoutId}";
    }

    /**
     * @return list<EntryDraft>
     */
    public function entries(): array
    {
        return [
            EntryDraft::debit($this->sellerAvailable, $this->amount),
            EntryDraft::credit($this->platformCash, $this->amount),
        ];
    }
}
