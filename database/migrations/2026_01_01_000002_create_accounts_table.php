<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $accountsTable = config('ledger.table_names.accounts', 'accounts');
        $ledgersTable = config('ledger.table_names.ledgers', 'ledgers');

        Schema::create($accountsTable, function (Blueprint $table) use ($ledgersTable) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ledger_id')->constrained($ledgersTable)->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type', 16);

            // Generated column: derived from `type`. Stored for index use.
            // Asset + Expense  → debit
            // Liability, Equity, Revenue → credit
            $table->string('normal_balance', 6)->storedAs(
                "CASE WHEN type IN ('asset','expense') THEN 'debit' ELSE 'credit' END"
            );

            $table->string('currency', 3);
            $table->string('ownerable_type')->nullable();
            $table->uuid('ownerable_id')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();

            $table->unique(['ledger_id', 'code']);
            $table->index(['ownerable_type', 'ownerable_id']);
            $table->index(['ledger_id', 'type', 'currency']);
        });

        // CHECK constraints. Laravel's blueprint does not expose CHECK
        // portably, so we add them per-driver here. SQLite supports CHECK
        // only at CREATE TABLE time and is covered by the PHP-level
        // Money / Validator enforcement (see docs/03-invariants.md).
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$accountsTable} ADD CONSTRAINT accounts_currency_format CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE {$accountsTable} ADD CONSTRAINT accounts_type_valid CHECK (type IN ('asset','liability','equity','revenue','expense'))");
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `{$accountsTable}` ADD CONSTRAINT accounts_currency_format CHECK (currency REGEXP '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE `{$accountsTable}` ADD CONSTRAINT accounts_type_valid CHECK (type IN ('asset','liability','equity','revenue','expense'))");
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists(config('ledger.table_names.accounts', 'accounts'));
        Schema::enableForeignKeyConstraints();
    }
};
