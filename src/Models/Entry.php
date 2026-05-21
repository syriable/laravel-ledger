<?php

declare(strict_types=1);

namespace Syriable\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Models\Concerns\WritableOnlyByRecorder;
use Syriable\Ledger\ValueObjects\Money;

/**
 * A single debit or credit line within a Transaction.
 *
 * Entries are the source of truth. Balances are derived; entries are never
 * mutated nor deleted. The amount is always positive — direction is encoded
 * by `direction`, not by sign.
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $ledger_id
 * @property string $account_id
 * @property EntryDirection $direction
 * @property int $amount
 * @property string $currency
 * @property CarbonImmutable $posted_at
 * @property array<string,mixed>|null $metadata
 */
class Entry extends Model
{
    use HasUuids;
    use WritableOnlyByRecorder;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * TIMESTAMP(6) columns — preserve microsecond precision so entries
     * keep their ordering within the same second.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'direction' => EntryDirection::class,
        'amount' => 'integer',
        'posted_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('ledger.table_names.entries', 'entries');

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

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Ledger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
