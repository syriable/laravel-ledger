<?php

declare(strict_types=1);

namespace Syriable\Ledger;

use Illuminate\Database\Eloquent\Model;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Exceptions\AccountNotFoundException;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Ledger as LedgerModel;

/**
 * A small fluent wrapper returned by Ledger::for('platform-main') that
 * scopes account operations to one ledger.
 *
 * This is sugar, not an extra layer — every method delegates back to
 * LedgerManager. The wrapper exists only so callers don't have to repeat
 * the ledger argument.
 */
final readonly class LedgerScope
{
    public function __construct(
        public LedgerModel $ledger,
        private LedgerManager $manager,
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function openAccount(
        string $code,
        AccountType $type,
        string $currency,
        ?string $name = null,
        ?Model $owner = null,
        array $metadata = [],
    ): Account {
        return $this->manager->openAccount(
            ledger: $this->ledger,
            code: $code,
            type: $type,
            currency: $currency,
            name: $name,
            owner: $owner,
            metadata: $metadata,
        );
    }

    public function account(string $code): Account
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->where('ledger_id', $this->ledger->id)
            ->where('code', $code)
            ->first();

        if ($account === null) {
            throw AccountNotFoundException::byCode($code, $this->ledger->slug);
        }

        return $account;
    }
}
