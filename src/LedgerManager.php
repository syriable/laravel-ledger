<?php

declare(strict_types=1);

namespace Syriable\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Syriable\Ledger\Data\PostingResult;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Events\AccountArchived;
use Syriable\Ledger\Events\AccountOpened;
use Syriable\Ledger\Exceptions\LedgerNotFoundException;
use Syriable\Ledger\Exceptions\ReversalNotAllowedException;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Ledger as LedgerModel;
use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\Postings\ReversalPosting;
use Syriable\Ledger\Recording\Clock;
use Syriable\Ledger\Recording\TransactionRecorder;
use Syriable\Ledger\ValueObjects\AccountCode;

/**
 * LedgerManager — the backing class behind the Ledger facade.
 *
 * Three verbs only: openAccount, post, reverse.
 * Plus the small lifecycle helpers: createLedger, for(slug), archiveAccount.
 */
final class LedgerManager
{
    public function __construct(
        private readonly TransactionRecorder $recorder,
        private readonly Clock $clock,
    ) {}

    /**
     * Create a new Ledger. Idempotent on slug — returns the existing ledger
     * if one exists, otherwise creates and emits no event (ledger lifecycle
     * is administrative, not financial).
     *
     * @param  array<string,mixed>  $metadata
     */
    public function createLedger(
        string $slug,
        string $currency,
        ?string $name = null,
        ?string $tenantId = null,
        array $metadata = [],
    ): LedgerModel {
        return $this->createOrRaceLookup(
            lookup: fn () => LedgerModel::query()->where('slug', $slug)->first(),
            build: function () use ($slug, $currency, $name, $tenantId, $metadata): LedgerModel {
                $ledger = new LedgerModel;
                $ledger->id = (string) Str::orderedUuid();
                $ledger->slug = $slug;
                $ledger->name = $name ?? $slug;
                $ledger->default_currency = strtoupper($currency);
                $ledger->tenant_id = $tenantId;
                $ledger->metadata = $metadata === [] ? null : $metadata;

                return $ledger;
            },
            openWindow: LedgerModel::openRecorderWindow(...),
            closeWindow: LedgerModel::closeRecorderWindow(...),
        );
    }

    /**
     * Scope subsequent calls (openAccount, etc.) to a ledger by slug.
     */
    public function for(string $slug): LedgerScope
    {
        /** @var LedgerModel|null $ledger */
        $ledger = LedgerModel::query()->where('slug', $slug)->first();
        if ($ledger === null) {
            throw LedgerNotFoundException::bySlug($slug);
        }

        return new LedgerScope($ledger, $this);
    }

    /**
     * Open an account inside a ledger. Idempotent on (ledger_id, code).
     *
     * @param  array<string,mixed>  $metadata
     */
    public function openAccount(
        LedgerModel $ledger,
        string $code,
        AccountType $type,
        string $currency,
        ?string $name = null,
        ?Model $owner = null,
        array $metadata = [],
    ): Account {
        $codeVo = new AccountCode($code);

        $lookup = fn () => Account::query()
            ->where('ledger_id', $ledger->id)
            ->where('code', $codeVo->value)
            ->first();

        $created = false;
        $account = $this->createOrRaceLookup(
            lookup: $lookup,
            build: function () use ($ledger, $codeVo, $type, $currency, $name, $owner, $metadata, &$created): Account {
                $created = true;

                $account = new Account;
                $account->id = (string) Str::orderedUuid();
                $account->ledger_id = $ledger->id;
                $account->code = $codeVo->value;
                $account->name = $name ?? $codeVo->value;
                $account->type = $type;
                $account->currency = strtoupper($currency);
                $account->is_archived = false;
                $account->metadata = $metadata === [] ? null : $metadata;

                if ($owner !== null) {
                    $account->ownerable_type = $owner::class;
                    /** @var string $ownerId */
                    $ownerId = $owner->getKey();
                    $account->ownerable_id = $ownerId;
                }

                return $account;
            },
            openWindow: Account::openRecorderWindow(...),
            closeWindow: Account::closeRecorderWindow(...),
        );

        if (! $created) {
            return $account;
        }

        // The generated `normal_balance` column is computed by the database;
        // reload it so the in-memory model sees the persisted value.
        $account->refresh();

        event(new AccountOpened($account));

        return $account;
    }

    /**
     * Shared helper for the "look up by natural key, create if missing, lose
     * the race gracefully" pattern. Returns either the existing row or the
     * newly created one.
     *
     * @template TModel of Model
     *
     * @param  callable():?TModel  $lookup  Returns the existing row, if any.
     * @param  callable():TModel  $build  Builds the unsaved candidate row.
     * @param  callable():void  $openWindow
     * @param  callable():void  $closeWindow
     * @return TModel
     */
    private function createOrRaceLookup(
        callable $lookup,
        callable $build,
        callable $openWindow,
        callable $closeWindow,
    ): Model {
        /** @var TModel|null $existing */
        $existing = $lookup();
        if ($existing !== null) {
            return $existing;
        }

        $openWindow();
        try {
            $model = $build();
            try {
                $model->save();
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race against another process — fetch and return
                // the winner. If the lookup still finds nothing the unique
                // violation was something else entirely; rethrow.
                $winner = $lookup();
                if ($winner === null) {
                    throw $e;
                }

                return $winner;
            }
        } finally {
            $closeWindow();
        }

        return $model;
    }

    /**
     * Archive an account. Archived accounts retain history but reject new
     * non-reversal entries.
     *
     * Captures an audit trail (archived_at, archived_by). The actor token
     * is intentionally free-form — pass a user id, a system actor string,
     * or null. The package stores whatever the caller provides.
     */
    public function archiveAccount(Account $account, ?string $actor = null): Account
    {
        if ($account->is_archived) {
            return $account;
        }

        Account::openRecorderWindow();
        try {
            $account->is_archived = true;
            $account->archived_at = $this->clock->now();
            $account->archived_by = $actor;
            $account->save();
        } finally {
            Account::closeRecorderWindow();
        }

        event(new AccountArchived($account));

        return $account;
    }

    /**
     * Post a Posting. Idempotent on the Posting's reference.
     */
    public function post(Posting $posting): PostingResult
    {
        $ledger = $this->resolveLedger($posting->ledger());
        $draft = $posting->toDraft($ledger->id, $this->clock);

        return $this->recorder->record($draft);
    }

    /**
     * Post many Postings atomically inside a single DB transaction.
     *
     * Either all postings commit, or none do. Useful for bulk legacy
     * imports and high-throughput workers that would otherwise pay a
     * per-posting transaction round-trip.
     *
     * Idempotency still applies per Posting: replays inside the batch
     * return wasReplayed=true and write nothing. The recorder's own
     * deadlock-retry budget applies per Posting (as a savepoint inside
     * the outer transaction).
     *
     * @param  iterable<Posting>  $postings
     * @return list<PostingResult>
     */
    public function postMany(iterable $postings): array
    {
        $results = [];

        DB::transaction(function () use ($postings, &$results): void {
            foreach ($postings as $posting) {
                $results[] = $this->post($posting);
            }
        });

        return $results;
    }

    /**
     * Reverse a prior transaction with a compensating one.
     */
    public function reverse(Transaction $original, ?string $reason = null): PostingResult
    {
        // Eager-load relations the reversal needs.
        $original->loadMissing(['entries', 'ledger']);

        if ($original->isReversed()) {
            throw ReversalNotAllowedException::alreadyReversed($original->id);
        }

        return $this->post(new ReversalPosting($original, $reason));
    }

    private function resolveLedger(string $slug): LedgerModel
    {
        /** @var LedgerModel|null $ledger */
        $ledger = LedgerModel::query()->where('slug', $slug)->first();
        if ($ledger === null) {
            throw LedgerNotFoundException::bySlug($slug);
        }

        return $ledger;
    }
}
