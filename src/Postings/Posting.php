<?php

declare(strict_types=1);

namespace Syriable\Ledger\Postings;

use Carbon\CarbonImmutable;
use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Recording\Clock;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * The Posting is the only public way to write to the ledger.
 *
 * Concrete Postings are domain operations: OrderPaidPosting,
 * PayoutInitiatedPosting, RefundPosting, and so on. Each one encapsulates
 * the rules of one business event and produces a TransactionDraft.
 *
 * Rules every Posting MUST follow:
 *   1. Determinism: given the same constructor inputs, entries() and
 *      reference() must return the same result every time. Retries depend
 *      on this.
 *   2. No I/O in entries(): no DB queries, no HTTP, no cache reads. All
 *      monetary values must be computed BEFORE construction.
 *   3. The reference must be dot-scoped (e.g. "order.paid:42"). The
 *      Reference value object enforces this.
 */
abstract class Posting
{
    /**
     * The slug of the ledger this posting writes to.
     */
    abstract public function ledger(): string;

    /**
     * A deterministic, dot-scoped idempotency key.
     */
    abstract public function reference(): Reference;

    /**
     * The transaction currency. Every entry must use the same currency.
     */
    abstract public function currency(): string;

    /**
     * The entries (debits and credits) that compose this transaction.
     *
     * @return list<EntryDraft>
     */
    abstract public function entries(): array;

    /**
     * Business time. Override to backdate; otherwise "now" via the Clock.
     */
    public function postedAt(Clock $clock): CarbonImmutable
    {
        return $clock->now();
    }

    /**
     * Optional human description.
     */
    public function description(): ?string
    {
        return null;
    }

    /**
     * Optional grouping key for linked operations (FX, transfers, …).
     */
    public function correlationId(): ?string
    {
        return null;
    }

    /**
     * Free-form, queryable-only-for-audit metadata.
     *
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return [];
    }

    /**
     * Internal: when this Posting is a reversal, the transaction it reverses.
     * Application code subclasses Posting, not ReversalPosting, and should
     * not override this.
     */
    public function reversesTransactionId(): ?string
    {
        return null;
    }

    /**
     * Stable, refactor-proof identifier persisted in transactions.posting_type.
     *
     * Defaults to the fully-qualified class name for backward compatibility.
     * Override with a short, stable domain token (e.g. "order.paid",
     * "payout.settled") so renames or namespace moves do not invalidate
     * historical rows. Once chosen, a Posting's type() must never change —
     * change it and you orphan every prior row that used the old value.
     *
     * Production guidance: choose a `domain.event` token at the same time
     * you choose the Reference scope, and keep them aligned.
     */
    public function type(): string
    {
        return static::class;
    }

    /**
     * @internal Called by the Ledger facade.
     */
    final public function toDraft(string $ledgerId, Clock $clock): TransactionDraft
    {
        return new TransactionDraft(
            ledgerId: $ledgerId,
            reference: $this->reference(),
            currency: $this->currency(),
            postedAt: $this->postedAt($clock),
            description: $this->description(),
            postingType: $this->type(),
            entries: $this->entries(),
            reversesTransactionId: $this->reversesTransactionId(),
            correlationId: $this->correlationId(),
            metadata: $this->metadata(),
        );
    }
}
