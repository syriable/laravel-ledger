<?php

declare(strict_types=1);

namespace Syriable\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Syriable\Ledger\Models\Concerns\WritableOnlyByRecorder;

/**
 * A Ledger is a book of accounts — a bounded financial context.
 *
 * Every Account, Transaction, and Entry belongs to exactly one Ledger.
 * No financial relationship crosses ledger boundaries.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $default_currency
 * @property array<string,mixed>|null $metadata
 */
class Ledger extends Model
{
    use HasUuids;
    use WritableOnlyByRecorder;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('ledger.table_names.ledgers', 'ledgers');

        return $table;
    }

    /**
     * UUIDv7-style ordered UUIDs for time-locality on B-trees.
     */
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

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
