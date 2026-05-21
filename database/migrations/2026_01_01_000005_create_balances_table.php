<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $balancesTable = config('ledger.table_names.balances', 'balances');
        $accountsTable = config('ledger.table_names.accounts', 'accounts');

        Schema::create($balancesTable, function (Blueprint $table) use ($accountsTable) {
            $table->foreignUuid('account_id')->primary()->constrained($accountsTable)->restrictOnDelete();
            $table->string('currency', 3);
            $table->unsignedBigInteger('debit_total')->default(0);
            $table->unsignedBigInteger('credit_total')->default(0);

            // Signed balance — sign reflects the account's normal balance.
            // Asset/Expense  → positive when debits exceed credits.
            // Liability/Equity/Revenue → positive when credits exceed debits.
            $table->bigInteger('balance')->default(0);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists(config('ledger.table_names.balances', 'balances'));
        Schema::enableForeignKeyConstraints();
    }
};
