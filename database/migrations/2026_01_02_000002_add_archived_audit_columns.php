<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds archived_at and archived_by columns to the accounts table so the
 * archive lifecycle leaves an audit trail instead of being a bare bool.
 *
 * archived_by is intentionally a free-form nullable string. The package
 * has no opinion on actor identity — pass a user id, a system actor
 * token, or null — but stores whatever you provide.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = config('ledger.table_names.accounts', 'accounts');

        Schema::table($accounts, function (Blueprint $table): void {
            $table->timestamp('archived_at', 6)->nullable()->after('is_archived');
            $table->string('archived_by')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        $accounts = config('ledger.table_names.accounts', 'accounts');

        Schema::table($accounts, function (Blueprint $table): void {
            $table->dropColumn(['archived_at', 'archived_by']);
        });
    }
};
