<?php

declare(strict_types=1);

namespace Syriable\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Syriable\Ledger\Enums\TransactionState;
use Syriable\Ledger\Models\Concerns\WritableOnlyByRecorder;

/**
 * An atomic, balanced, immutable journal entry — the heart of double-entry.
 *
 * Once recorded, no column on a Transaction is ever mutated. "Was this
 * transaction reversed?" is answered by the existence of another transaction
 * pointing at it via reverses_transaction_id, not by mutating this row.
 *
 * @property string $id
 * @property string $ledger_id
 * @property string $reference
 * @property string $posting_type
 * @property string $currency
 * @property string|null $description
 * @property CarbonImmutable $posted_at
 * @property CarbonImmutable $recorded_at
 * @property string|null $reverses_transaction_id
 * @property string|null $correlation_id
 * @property array<string,mixed>|null $metadata
 */
class Transaction extends Model
{
    use HasUuids;
    use WritableOnlyByRecorder;

    public $incrementing = false;

    /**
     * Created_at / updated_at would imply mutability. Transactions carry
     * posted_at (business time) and recorded_at (system time) instead.
     */
    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Store timestamps with microsecond precision. The columns are
     * TIMESTAMP(6); without this, Eloquent would serialise to whole
     * seconds and collapse postings that happen within the same second.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'posted_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('ledger.table_names.transactions', 'transactions');

        return $table;
    }

    public function newUniqueId(): string
    {
        return (string) Str::orderedUuid();
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /** @return BelongsTo<Ledger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * The transaction this one reverses (NULL on ordinary postings).
     *
     * @return BelongsTo<self, $this>
     */
    public function reversesTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /**
     * The reversal of this transaction, if any. Derived — no back-link column.
     *
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    /**
     * Derived state. NEVER stored.
     */
    public function state(): TransactionState
    {
        return $this->reversal()->exists()
            ? TransactionState::Reversed
            : TransactionState::Posted;
    }

    public function isReversed(): bool
    {
        return $this->state() === TransactionState::Reversed;
    }

    public function isReversal(): bool
    {
        return $this->reverses_transaction_id !== null;
    }
}
