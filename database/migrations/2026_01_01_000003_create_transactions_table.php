<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $transactionsTable = config('ledger.table_names.transactions', 'transactions');
        $ledgersTable = config('ledger.table_names.ledgers', 'ledgers');

        Schema::create($transactionsTable, function (Blueprint $table) use ($ledgersTable, $transactionsTable) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ledger_id')->constrained($ledgersTable)->restrictOnDelete();
            $table->string('reference');
            $table->string('posting_type');
            $table->string('currency', 3);
            $table->string('description')->nullable();

            // Business time (caller-supplied) vs system time (recorder-supplied).
            $table->timestamp('posted_at', 6);
            $table->timestamp('recorded_at', 6);

            // The transaction this one reverses (NULL for ordinary postings).
            $table->foreignUuid('reverses_transaction_id')
                ->nullable()
                ->constrained($transactionsTable)
                ->restrictOnDelete();

            // Optional grouping key (used by future FX / linked operations).
            $table->uuid('correlation_id')->nullable();

            $table->json('metadata')->nullable();

            // The idempotency boundary.
            $table->unique(['ledger_id', 'reference']);

            // A transaction can be reversed at most once.
            // NULLs are treated as distinct in UNIQUE on both Postgres and MySQL 8,
            // so this gives us "at most one reversal per original" without needing
            // a partial index expression that diverges by driver.
            $table->unique('reverses_transaction_id');

            $table->index(['ledger_id', 'posted_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        // transactions.reverses_transaction_id is a self-referencing FK,
        // which blocks DROP TABLE while constraints are enforced (notably
        // on SQLite). Disable enforcement around the drop.
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists(config('ledger.table_names.transactions', 'transactions'));
        Schema::enableForeignKeyConstraints();
    }
};
