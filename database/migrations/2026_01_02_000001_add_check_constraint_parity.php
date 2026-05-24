<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills CHECK constraints introduced after 0.9.0 for existing
 * installations on PostgreSQL, MySQL, and MariaDB.
 *
 * SQLite does not support adding CHECK constraints via ALTER TABLE; on
 * SQLite the package relies on the PHP-level Money/Validator enforcement
 * for these invariants (documented in docs/03-invariants.md).
 *
 * Idempotent: skips constraints that are already present so re-running on
 * a fresh install (where the original CREATE TABLE migrations already
 * added the constraints) is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'pgsql' && $driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $accounts = config('ledger.table_names.accounts', 'accounts');
        $entries = config('ledger.table_names.entries', 'entries');
        $transactions = config('ledger.table_names.transactions', 'transactions');

        $constraints = [
            [$accounts, 'accounts_currency_format', 'currency', 'regex'],
            [$accounts, 'accounts_type_valid', 'type', 'account_type'],
            [$entries, 'entries_amount_positive', 'amount', 'positive_amount'],
            [$entries, 'entries_direction_valid', 'direction', 'direction'],
            [$entries, 'entries_currency_format', 'currency', 'regex'],
            [$transactions, 'transactions_currency_format', 'currency', 'regex'],
        ];

        foreach ($constraints as [$table, $name, $column, $kind]) {
            if ($this->constraintExists($table, $name)) {
                continue;
            }

            $expression = $this->expressionFor($kind, $column, $driver);
            if ($expression === null) {
                continue;
            }

            $quoted = $driver === 'pgsql' ? $table : "`{$table}`";
            DB::statement("ALTER TABLE {$quoted} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'pgsql' && $driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $tables = [
            config('ledger.table_names.accounts', 'accounts') => [
                'accounts_currency_format',
                'accounts_type_valid',
            ],
            config('ledger.table_names.entries', 'entries') => [
                'entries_amount_positive',
                'entries_direction_valid',
                'entries_currency_format',
            ],
            config('ledger.table_names.transactions', 'transactions') => [
                'transactions_currency_format',
            ],
        ];

        foreach ($tables as $table => $names) {
            foreach ($names as $name) {
                $quoted = $driver === 'pgsql' ? $table : "`{$table}`";
                try {
                    DB::statement("ALTER TABLE {$quoted} DROP CONSTRAINT {$name}");
                } catch (Throwable) {
                    // Constraint was never created (e.g. fresh install on this
                    // migration before the originals). Safe to ignore.
                }
            }
        }
    }

    private function expressionFor(string $kind, string $column, string $driver): ?string
    {
        return match ($kind) {
            'regex' => $driver === 'pgsql'
                ? "{$column} ~ '^[A-Z]{3}$'"
                : "{$column} REGEXP '^[A-Z]{3}$'",
            'account_type' => "{$column} IN ('asset','liability','equity','revenue','expense')",
            'positive_amount' => "{$column} > 0",
            'direction' => "{$column} IN ('debit','credit')",
            default => null,
        };
    }

    private function constraintExists(string $table, string $name): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS hit FROM pg_constraint c
                 JOIN pg_class t ON t.oid = c.conrelid
                 WHERE t.relname = ? AND c.conname = ?',
                [$table, $name],
            );

            return $row !== null;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = DB::selectOne(
                'SELECT 1 AS hit FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
                [$table, $name],
            );

            return $row !== null;
        }

        return false;
    }
};
