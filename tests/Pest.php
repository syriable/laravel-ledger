<?php

declare(strict_types=1);

use Pest\Expectation;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Balance;
use Syriable\Ledger\Models\Entry;
use Syriable\Ledger\Models\Ledger as LedgerModel;
use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Custom expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeBalanced', function (): Expectation {
    /** @var Transaction $transaction */
    $transaction = $this->value;

    $debits = (int) Entry::query()
        ->where('transaction_id', $transaction->id)
        ->where('direction', EntryDirection::Debit->value)
        ->sum('amount');

    $credits = (int) Entry::query()
        ->where('transaction_id', $transaction->id)
        ->where('direction', EntryDirection::Credit->value)
        ->sum('amount');

    expect($debits)->toBe($credits, "Transaction {$transaction->id} is not balanced: debits={$debits} credits={$credits}");

    return $this;
});

expect()->extend('toHaveZeroSum', function (): Expectation {
    /** @var LedgerModel $ledger */
    $ledger = $this->value;

    $debits = (int) Entry::query()
        ->where('ledger_id', $ledger->id)
        ->where('direction', EntryDirection::Debit->value)
        ->sum('amount');

    $credits = (int) Entry::query()
        ->where('ledger_id', $ledger->id)
        ->where('direction', EntryDirection::Credit->value)
        ->sum('amount');

    expect($debits)->toBe($credits, "Ledger {$ledger->slug} does not have zero-sum: debits={$debits} credits={$credits}");

    return $this;
});

expect()->extend('toHaveBalancesEqualEntries', function (): Expectation {
    /** @var LedgerModel $ledger */
    $ledger = $this->value;

    $accountIds = Account::query()->where('ledger_id', $ledger->id)->pluck('id');

    foreach ($accountIds as $accountId) {
        /** @var Account $account */
        $account = Account::query()->find($accountId);

        $debits = (int) Entry::query()
            ->where('account_id', $accountId)
            ->where('direction', EntryDirection::Debit->value)
            ->sum('amount');

        $credits = (int) Entry::query()
            ->where('account_id', $accountId)
            ->where('direction', EntryDirection::Credit->value)
            ->sum('amount');

        $signedFromEntries = $account->signMultiplier(EntryDirection::Debit) * $debits
            + (-$account->signMultiplier(EntryDirection::Debit)) * $credits;

        /** @var Balance|null $projection */
        $projection = Balance::query()->where('account_id', $accountId)->first();
        $projected = $projection?->balance ?? 0;

        expect($projected)->toBe(
            $signedFromEntries,
            "Account {$account->code} projection ({$projected}) does not match entries ({$signedFromEntries})"
        );
    }

    return $this;
});
