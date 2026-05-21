<?php

declare(strict_types=1);

namespace Syriable\Ledger\Data;

use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\ValueObjects\Money;

/**
 * A draft of a single entry inside a TransactionDraft.
 *
 * Drafts are pure data: they carry no relationship to persisted state and
 * are never reused across transactions. They are built by Postings and
 * consumed by the TransactionRecorder.
 */
final readonly class EntryDraft
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $accountId,
        public EntryDirection $direction,
        public Money $amount,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public static function debit(Account|string $account, Money $amount, array $metadata = []): self
    {
        return new self(self::accountId($account), EntryDirection::Debit, $amount, $metadata);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public static function credit(Account|string $account, Money $amount, array $metadata = []): self
    {
        return new self(self::accountId($account), EntryDirection::Credit, $amount, $metadata);
    }

    private static function accountId(Account|string $account): string
    {
        if ($account instanceof Account) {
            /** @var string $id */
            $id = $account->getKey();

            return $id;
        }

        return $account;
    }
}
