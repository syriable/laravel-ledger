<?php

declare(strict_types=1);

namespace Syriable\Ledger\Postings;

use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Exceptions\ReversalNotAllowedException;
use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * A ReversalPosting inverts a prior Transaction by swapping the direction
 * of every entry. It is itself a Posting — recorded as a new, immutable
 * transaction — and never mutates the original.
 *
 * The recorder's UNIQUE(reverses_transaction_id) index enforces that a
 * transaction can be reversed at most once. Additionally, the constructor
 * refuses to build a reversal-of-a-reversal: if the original transaction
 * is itself a reversal, the correct action is to post a new operation
 * rather than re-applying old state.
 */
final class ReversalPosting extends Posting
{
    public function __construct(
        private readonly Transaction $original,
        private readonly ?string $reason = null,
    ) {
        if ($this->original->isReversal()) {
            throw ReversalNotAllowedException::cannotReverseAReversal($this->original->id);
        }
    }

    public function ledger(): string
    {
        $ledger = $this->original->ledger;

        if ($ledger === null) {
            throw new \RuntimeException(
                "Transaction {$this->original->id} has no ledger; cannot build a reversal."
            );
        }

        /** @var string $slug */
        $slug = $ledger->slug;

        return $slug;
    }

    public function reference(): Reference
    {
        return Reference::for('ledger.reversal', $this->original->id);
    }

    public function currency(): string
    {
        return $this->original->currency;
    }

    public function description(): string
    {
        $base = "Reversal of transaction {$this->original->id}";

        return $this->reason !== null ? "{$base}: {$this->reason}" : $base;
    }

    public function type(): string
    {
        return 'ledger.reversal';
    }

    public function correlationId(): ?string
    {
        return $this->original->correlation_id;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        $meta = ['reason' => $this->reason];

        return array_filter($meta, static fn ($v) => $v !== null);
    }

    public function reversesTransactionId(): string
    {
        return $this->original->id;
    }

    /**
     * @return list<EntryDraft>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->original->entries as $entry) {
            $entries[] = new EntryDraft(
                accountId: $entry->account_id,
                direction: $entry->direction->opposite(),
                amount: Money::of($entry->amount, $entry->currency),
                metadata: ['reverses_entry_id' => $entry->id],
            );
        }

        return $entries;
    }
}
