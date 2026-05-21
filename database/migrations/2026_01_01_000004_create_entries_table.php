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
        $entriesTable = config('ledger.table_names.entries', 'entries');
        $transactionsTable = config('ledger.table_names.transactions', 'transactions');
        $ledgersTable = config('ledger.table_names.ledgers', 'ledgers');
        $accountsTable = config('ledger.table_names.accounts', 'accounts');

        Schema::create($entriesTable, function (Blueprint $table) use ($transactionsTable, $ledgersTable, $accountsTable) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained($transactionsTable)->restrictOnDelete();
            $table->foreignUuid('ledger_id')->constrained($ledgersTable)->restrictOnDelete();
            $table->foreignUuid('account_id')->constrained($accountsTable)->restrictOnDelete();
            $table->string('direction', 6);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestamp('posted_at', 6);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 6)->nullable();

            $table->index(['account_id', 'posted_at']);
            $table->index('transaction_id');
            $table->index(['ledger_id', 'posted_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$entriesTable} ADD CONSTRAINT entries_amount_positive CHECK (amount > 0)");
            DB::statement("ALTER TABLE {$entriesTable} ADD CONSTRAINT entries_direction_valid CHECK (direction IN ('debit','credit'))");
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists(config('ledger.table_names.entries', 'entries'));
        Schema::enableForeignKeyConstraints();
    }
};
