<?php

declare(strict_types=1);

namespace Syriable\Ledger\Data;

use Carbon\CarbonImmutable;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * The complete, frozen draft of one balanced double-entry transaction.
 *
 * Built by Posting::toDraft() and consumed only by the TransactionRecorder.
 * Drafts are immutable; the recorder snapshots the draft once before entering
 * the DB transaction so deadlock retries cannot recompute it.
 */
final readonly class TransactionDraft
{
    /**
     * @param  list<EntryDraft>  $entries
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $ledgerId,
        public Reference $reference,
        public string $currency,
        public CarbonImmutable $postedAt,
        public ?string $description,
        public string $postingType,
        public array $entries,
        public ?string $reversesTransactionId = null,
        public ?string $correlationId = null,
        public array $metadata = [],
    ) {}

    /**
     * The unique account IDs referenced by this draft, sorted ascending so
     * the recorder can lock them in a deterministic order (deadlock-free).
     *
     * @return list<string>
     */
    public function uniqueAccountIds(): array
    {
        $ids = [];
        foreach ($this->entries as $entry) {
            $ids[$entry->accountId] = true;
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }
}
