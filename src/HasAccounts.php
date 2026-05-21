<?php

declare(strict_types=1);

namespace Syriable\Ledger;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Exceptions\AccountNotFoundException;
use Syriable\Ledger\Exceptions\LedgerNotFoundException;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Ledger as LedgerModel;

/**
 * Apply to any model that owns ledger accounts (User, Order, Wallet, …).
 *
 *   class User extends Model {
 *       use HasAccounts;
 *   }
 *
 *   $user->openAccount('available.usd', AccountType::Liability, 'USD');
 *   $user->account('available.usd')->balance();
 */
trait HasAccounts
{
    public function accounts(): MorphMany
    {
        return $this->morphMany(Account::class, 'ownerable');
    }

    /**
     * Open an account owned by this model. Defaults to the model's own
     * ledger slug if `$ledgerSlug` is null and a `ledger_slug` attribute
     * or `defaultLedgerSlug()` method is available; otherwise required.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function openAccount(
        string $code,
        AccountType $type,
        string $currency,
        ?string $ledgerSlug = null,
        ?string $name = null,
        array $metadata = [],
    ): Account {
        $ledger = $this->resolveLedger($ledgerSlug);

        return Ledger::openAccount(
            ledger: $ledger,
            code: $code,
            type: $type,
            currency: $currency,
            name: $name,
            owner: $this,
            metadata: $metadata,
        );
    }

    /**
     * Retrieve one of this model's accounts by its code.
     */
    public function account(string $code): Account
    {
        /** @var Account|null $account */
        $account = $this->accounts()->where('code', $code)->first();

        if ($account === null) {
            throw AccountNotFoundException::byCode($code, (string) $this->getKey());
        }

        return $account;
    }

    private function resolveLedger(?string $ledgerSlug): LedgerModel
    {
        if ($ledgerSlug !== null) {
            /** @var LedgerModel|null $ledger */
            $ledger = LedgerModel::query()->where('slug', $ledgerSlug)->first();
            if ($ledger === null) {
                throw LedgerNotFoundException::bySlug($ledgerSlug);
            }

            return $ledger;
        }

        if (method_exists($this, 'defaultLedgerSlug')) {
            $slug = $this->defaultLedgerSlug();
            /** @var LedgerModel|null $ledger */
            $ledger = LedgerModel::query()->where('slug', $slug)->first();
            if ($ledger === null) {
                throw LedgerNotFoundException::bySlug($slug);
            }

            return $ledger;
        }

        $configured = config('ledger.default_ledger_slug');
        if (is_string($configured) && $configured !== '') {
            /** @var LedgerModel|null $ledger */
            $ledger = LedgerModel::query()->where('slug', $configured)->first();
            if ($ledger === null) {
                throw LedgerNotFoundException::bySlug($configured);
            }

            return $ledger;
        }

        throw new \InvalidArgumentException(
            'No ledger slug provided to openAccount() and no default is configured. '.
            'Pass $ledgerSlug, define defaultLedgerSlug() on the model, or set config(ledger.default_ledger_slug).'
        );
    }
}
