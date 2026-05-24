<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Syriable\Ledger\Data\PostingResult;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Events\TransactionPosted;
use Syriable\Ledger\Events\TransactionReversed;
use Syriable\Ledger\Exceptions\AccountNotFoundException;
use Syriable\Ledger\Exceptions\DuplicateReferenceException;
use Syriable\Ledger\Exceptions\LedgerException;
use Syriable\Ledger\Exceptions\LedgerWriteFailedException;
use Syriable\Ledger\Exceptions\ReversalNotAllowedException;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Entry;
use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\Validators\ValidatorPipeline;
use Throwable;

/**
 * The TransactionRecorder is the ONLY code in the entire package allowed
 * to write to the financial tables.
 *
 * The contract:
 *   1. Snapshot the draft once, before entering the DB transaction.
 *      Deadlock retries reuse the same snapshot so they cannot be tricked
 *      by recomputed state.
 *   2. Short-circuit on idempotent replay BEFORE doing any work.
 *   3. Inside DB::transaction(..., $attempts):
 *      a. SELECT ... FOR UPDATE on accounts, ordered ascending by id.
 *      b. Run the validator pipeline (pure, no I/O).
 *      c. Insert the transaction row.
 *      d. Bulk insert all entries in a single statement.
 *      e. Update the balances projection.
 *   4. Catch UNIQUE constraint violations on (ledger_id, reference) as
 *      racing replays — re-fetch and return wasReplayed=true.
 *   5. Dispatch the TransactionPosted event from within DB::afterCommit,
 *      exactly once, only after the transaction is durable.
 *
 * @internal Do not call this class directly from application code.
 *           Use Ledger::post() and Ledger::reverse() instead.
 */
final class TransactionRecorder
{
    private const DEFAULT_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ValidatorPipeline $validators,
        private readonly BalanceProjector $projector,
        private readonly IdempotencyStore $idempotencyStore,
        private readonly Clock $clock,
    ) {}

    /**
     * @return int<1, max>
     */
    private function maxAttempts(): int
    {
        $value = config('ledger.recorder.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
        $attempts = is_numeric($value) ? (int) $value : self::DEFAULT_MAX_ATTEMPTS;

        return max(1, $attempts);
    }

    public function record(TransactionDraft $draft): PostingResult
    {
        // 1. Cheap pre-check — short-circuit replays before any DB transaction work.
        $match = $this->idempotencyStore->find($draft->ledgerId, $draft->reference);
        if ($match !== null) {
            return new PostingResult($this->hydrateReplay($match), wasReplayed: true);
        }

        // 2. Persist atomically with deadlock retries. The draft is captured by
        //    closure once; retries operate on the same frozen snapshot.
        $attempts = $this->maxAttempts();
        try {
            $transaction = DB::transaction(
                fn () => $this->writeAtomically($draft),
                $attempts,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Racing concurrent insert under the same reference, or under
            // reverses_transaction_id (the once-only reversal index).
            return $this->resolveConstraintViolation($draft, $e);
        } catch (QueryException $e) {
            // Some MySQL/SQLite drivers don't always map to the typed exception above.
            if ($this->isUniqueViolation($e)) {
                return $this->resolveConstraintViolation($draft, $e);
            }

            // Deadlock budget exhausted or some other terminal DB failure
            // surfaced through DB::transaction(...) after maxAttempts().
            if ($this->isDeadlockOrLockTimeout($e)) {
                throw LedgerWriteFailedException::afterRetries(
                    $draft->ledgerId,
                    (string) $draft->reference,
                    $attempts,
                    $e,
                );
            }

            throw LedgerWriteFailedException::unexpected(
                $draft->ledgerId,
                (string) $draft->reference,
                $e,
            );
        } catch (LedgerException $e) {
            // Validators, account-not-found, etc. — already package-typed.
            throw $e;
        } catch (Throwable $e) {
            // Anything else that escaped DB::transaction() (e.g. driver
            // RuntimeExceptions, transient I/O) is wrapped so consumers can
            // catch LedgerException for all package-level write failures.
            throw LedgerWriteFailedException::unexpected(
                $draft->ledgerId,
                (string) $draft->reference,
                $e,
            );
        }

        // 3. After-commit, exactly-once event dispatch. For reversals we
        //    resolve the original transaction inside the still-open DB
        //    transaction so the afterCommit callback does not have to
        //    re-query — a post-commit DB blip would otherwise raise after
        //    a successful write.
        $original = null;
        if ($transaction->isReversal()) {
            /** @var Transaction $original */
            $original = $transaction->reversesTransaction()->firstOrFail();
        }

        DB::afterCommit(function () use ($transaction, $original): void {
            if ($original !== null) {
                event(new TransactionReversed($transaction, $original));
            } else {
                event(new TransactionPosted($transaction));
            }
        });

        return new PostingResult($transaction, wasReplayed: false);
    }

    private function writeAtomically(TransactionDraft $draft): Transaction
    {
        // Lock the affected accounts in deterministic ascending order
        // to eliminate A↔B deadlock cycles between concurrent postings.
        /** @var Collection<string, Account> $accounts */
        $accounts = Account::query()
            ->whereIn('id', $draft->uniqueAccountIds())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $missing = array_values(array_diff($draft->uniqueAccountIds(), $accounts->keys()->all()));
        if ($missing !== []) {
            throw AccountNotFoundException::ids($missing);
        }

        // Pure, in-memory checks. No I/O permitted in validators.
        $this->validators->validate($draft, $accounts);

        // Persist the transaction row.
        $transaction = $this->persistTransaction($draft);

        // Persist the entries in a single bulk insert.
        $entries = $this->persistEntries($draft, $transaction);

        // Apply to the balances projection within the same DB transaction.
        $this->projector->apply($entries, $accounts);

        return $transaction;
    }

    private function persistTransaction(TransactionDraft $draft): Transaction
    {
        Transaction::openRecorderWindow();
        try {
            $transaction = new Transaction;
            $transaction->id = (string) Str::orderedUuid();
            $transaction->ledger_id = $draft->ledgerId;
            $transaction->reference = (string) $draft->reference;
            $transaction->posting_type = $draft->postingType;
            $transaction->currency = $draft->currency;
            $transaction->description = $draft->description;
            $transaction->posted_at = $draft->postedAt;
            $transaction->recorded_at = $this->clock->now();
            $transaction->reverses_transaction_id = $draft->reversesTransactionId;
            $transaction->correlation_id = $draft->correlationId;
            $transaction->metadata = $draft->metadata === [] ? null : $draft->metadata;
            $transaction->save();

            return $transaction;
        } finally {
            Transaction::closeRecorderWindow();
        }
    }

    /**
     * @return list<Entry>
     */
    private function persistEntries(TransactionDraft $draft, Transaction $transaction): array
    {
        $rows = [];
        $now = $this->clock->now();

        // The query builder's insert() path serialises dates with the
        // connection's default (second-precision) format. Entries carry
        // TIMESTAMP(6) columns, so we format to microseconds explicitly
        // to preserve ordering between postings in the same second.
        $postedAt = $draft->postedAt->format('Y-m-d H:i:s.u');
        $createdAt = $now->format('Y-m-d H:i:s.u');

        foreach ($draft->entries as $entryDraft) {
            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'transaction_id' => $transaction->id,
                'ledger_id' => $draft->ledgerId,
                'account_id' => $entryDraft->accountId,
                'direction' => $entryDraft->direction->value,
                'amount' => $entryDraft->amount->minorUnits,
                'currency' => $entryDraft->amount->currency,
                'posted_at' => $postedAt,
                'metadata' => $entryDraft->metadata === [] ? null : json_encode($entryDraft->metadata),
                'created_at' => $createdAt,
            ];
        }

        // Bulk insert — one round-trip.
        DB::table((new Entry)->getTable())->insert($rows);

        // Hydrate Entry models for the projector. We avoid a SELECT round-trip
        // by reusing the rows we just wrote.
        $entries = [];
        foreach ($rows as $row) {
            $entry = (new Entry)->newFromBuilder([
                'id' => $row['id'],
                'transaction_id' => $row['transaction_id'],
                'ledger_id' => $row['ledger_id'],
                'account_id' => $row['account_id'],
                'direction' => $row['direction'],
                'amount' => $row['amount'],
                'currency' => $row['currency'],
                'posted_at' => $row['posted_at'],
                'metadata' => $row['metadata'],
                'created_at' => $row['created_at'],
            ]);
            $entries[] = $entry;
        }

        return $entries;
    }

    private function resolveConstraintViolation(TransactionDraft $draft, Throwable $e): PostingResult
    {
        // A racing concurrent insert under the same reference is the
        // expected case: we re-fetch and return wasReplayed=true.
        $match = $this->idempotencyStore->find($draft->ledgerId, $draft->reference);
        if ($match !== null) {
            return new PostingResult($this->hydrateReplay($match), wasReplayed: true);
        }

        // The violation was on reverses_transaction_id, not on reference —
        // meaning another caller already reversed this transaction.
        if ($draft->reversesTransactionId !== null) {
            throw ReversalNotAllowedException::alreadyReversed($draft->reversesTransactionId);
        }

        // Anything else: bubble up as a clean DuplicateReferenceException so
        // consumers can react without parsing driver-specific SQLSTATE codes.
        throw DuplicateReferenceException::for($draft->ledgerId, (string) $draft->reference);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 (MySQL/SQLite) or 23505 (Postgres) — integrity violation.
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23000' || $sqlState === '23505';
    }

    private function hydrateReplay(IdempotencyMatch $match): Transaction
    {
        /** @var Transaction $transaction */
        $transaction = Transaction::query()->findOrFail($match->transactionId);

        return $transaction;
    }

    private function isDeadlockOrLockTimeout(QueryException $e): bool
    {
        // SQLSTATE 40001 (serialization failure) / 40P01 (Postgres deadlock)
        // / 1213 (MySQL deadlock) / 1205 (MySQL lock wait timeout).
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        if ($sqlState === '40001' || $sqlState === '40P01') {
            return true;
        }

        return $driverCode === 1213 || $driverCode === 1205;
    }
}
