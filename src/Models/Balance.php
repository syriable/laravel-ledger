<?php

declare(strict_types=1);

namespace Syriable\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Syriable\Ledger\Models\Concerns\WritableOnlyByRecorder;

/**
 * Balance — a projection (cache) of an account's running totals.
 *
 * NOT the source of truth. Always rebuildable from entries.
 *
 * @property string $account_id
 * @property string $currency
 * @property int $debit_total
 * @property int $credit_total
 * @property int $balance
 * @property int $version
 * @property CarbonImmutable|null $updated_at
 */
class Balance extends Model
{
    use WritableOnlyByRecorder;

    public $incrementing = false;

    protected $primaryKey = 'account_id';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'debit_total' => 'integer',
        'credit_total' => 'integer',
        'balance' => 'integer',
        'version' => 'integer',
        'updated_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('ledger.table_names.balances', 'balances');

        return $table;
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
