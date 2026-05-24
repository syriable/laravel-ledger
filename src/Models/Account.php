<?php

declare(strict_types=1);

namespace Syriable\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Enums\NormalBalance;
use Syriable\Ledger\Models\Concerns\WritableOnlyByRecorder;
use Syriable\Ledger\ValueObjects\Money;

/**
 * An Account is a bucket inside a Ledger.
 *
 * Accounts have an immutable currency and an immutable ledger_id; once opened
 * they may only be archived (binary lifecycle).
 *
 * @property string $id
 * @property string $ledger_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property NormalBalance $normal_balance
 * @property string $currency
 * @property string|null $ownerable_type
 * @property string|null $ownerable_id
 * @property string|null $parent_id
 * @property bool $is_archived
 * @property CarbonImmutable|null $archived_at
 * @property string|null $archived_by
 * @property array<string,mixed>|null $metadata
 */
class Account extends Model
{
    use HasUuids;
    use WritableOnlyByRecorder;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'type' => AccountType::class,
        'normal_balance' => NormalBalance::class,
        'is_archived' => 'bool',
        'archived_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('ledger.table_names.accounts', 'accounts');

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

    /** @return MorphTo<Model, $this> */
    public function ownerable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /** @return HasOne<Balance, $this> */
    public function balanceProjection(): HasOne
    {
        return $this->hasOne(Balance::class);
    }

    /**
     * The current signed balance from the projection.
     *
     * Sign matches the account's normal balance: an Asset/Expense account
     * is positive when debits exceed credits; a Liability/Equity/Revenue
     * account is positive when credits exceed debits. A negative value
     * means the account is "underwater" with respect to its normal side
     * (e.g. an overdrawn cash account).
     *
     * For historical balances, query entries directly.
     */
    public function balance(): int
    {
        $balance = $this->balanceProjection;

        return $balance ? (int) $balance->balance : 0;
    }

    /**
     * The current balance as a Money value object.
     *
     * Throws when the projection is negative — that case is unambiguous and
     * a Money cannot represent it. Callers handling overdraft scenarios
     * should use balance() directly.
     */
    public function balanceMoney(): Money
    {
        return Money::of($this->balance(), $this->currency);
    }

    /**
     * Sign multiplier for an entry on this account.
     *
     * If the entry direction matches the account's normal balance, the entry
     * INCREASES the account (+). Otherwise it DECREASES it (−).
     */
    public function signMultiplier(EntryDirection $direction): int
    {
        return $direction->value === $this->normal_balance->value ? 1 : -1;
    }

    /**
     * Historical balance from entries (the source of truth), as of a moment
     * in time. Slower than balance() but authoritative — used by ledger:verify
     * and by audit workflows.
     *
     * "As of $at" means: includes every entry with posted_at <= $at.
     */
    public function balanceAsOf(\DateTimeInterface $at): int
    {
        // Entries store posted_at as TIMESTAMP(6). Format the cutoff to the
        // same microsecond precision so the comparison is exact — passing a
        // raw DateTime would be serialised to whole seconds by the query
        // builder and would mis-include entries from the same second.
        $cutoff = CarbonImmutable::instance($at)->format('Y-m-d H:i:s.u');

        $debits = (int) Entry::query()
            ->where('account_id', $this->id)
            ->where('direction', EntryDirection::Debit->value)
            ->where('posted_at', '<=', $cutoff)
            ->sum('amount');

        $credits = (int) Entry::query()
            ->where('account_id', $this->id)
            ->where('direction', EntryDirection::Credit->value)
            ->where('posted_at', '<=', $cutoff)
            ->sum('amount');

        $sign = $this->signMultiplier(EntryDirection::Debit);

        return ($sign * $debits) + (-$sign * $credits);
    }
}
