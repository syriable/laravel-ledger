<?php

declare(strict_types=1);

namespace Syriable\Ledger\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Syriable\Ledger\Data\PostingResult;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\LedgerManager;
use Syriable\Ledger\LedgerScope;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Ledger as LedgerModel;
use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\Postings\Posting;

/**
 * The Ledger facade — the public API surface of the package.
 *
 * @method static LedgerModel createLedger(string $slug, string $currency, ?string $name = null, ?string $tenantId = null, array<string,mixed> $metadata = [])
 * @method static LedgerScope for(string $slug)
 * @method static Account openAccount(LedgerModel $ledger, string $code, AccountType $type, string $currency, ?string $name = null, ?Model $owner = null, array<string,mixed> $metadata = [])
 * @method static Account archiveAccount(Account $account, ?string $actor = null)
 * @method static PostingResult post(Posting $posting)
 * @method static list<PostingResult> postMany(iterable<Posting> $postings)
 * @method static PostingResult reverse(Transaction $original, ?string $reason = null)
 *
 * @see LedgerManager
 */
final class Ledger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LedgerManager::class;
    }
}
