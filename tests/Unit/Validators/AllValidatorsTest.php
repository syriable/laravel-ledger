<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Syriable\Ledger\Data\EntryDraft;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Exceptions\AccountArchivedException;
use Syriable\Ledger\Exceptions\AccountCurrencyMismatchException;
use Syriable\Ledger\Exceptions\AccountNotFoundException;
use Syriable\Ledger\Exceptions\ImbalancedTransactionException;
use Syriable\Ledger\Exceptions\LedgerScopeViolationException;
use Syriable\Ledger\Exceptions\MinimumEntriesNotMetException;
use Syriable\Ledger\Exceptions\MixedCurrencyException;
use Syriable\Ledger\Exceptions\NonPositiveAmountException;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Validators\AccountCurrencyMatchValidator;
use Syriable\Ledger\Validators\AccountStateValidator;
use Syriable\Ledger\Validators\BalancedTransactionValidator;
use Syriable\Ledger\Validators\LedgerScopeValidator;
use Syriable\Ledger\Validators\MinimumEntriesValidator;
use Syriable\Ledger\Validators\PositiveAmountValidator;
use Syriable\Ledger\Validators\SingleCurrencyValidator;
use Syriable\Ledger\ValueObjects\Money;
use Syriable\Ledger\ValueObjects\Reference;

/**
 * Build a lightweight Account model without touching the DB.
 *
 * @param  array<string,mixed>  $overrides
 */
function fakeAccount(string $id, string $ledgerId = 'ledger-A', string $currency = 'USD', AccountType $type = AccountType::Asset, bool $archived = false, array $overrides = []): Account
{
    $account = new Account;
    $account->forceFill(array_merge([
        'id' => $id,
        'ledger_id' => $ledgerId,
        'code' => 'fake.'.$id,
        'name' => 'Fake '.$id,
        'type' => $type->value,
        'normal_balance' => $type->normalBalance()->value,
        'currency' => $currency,
        'is_archived' => $archived,
    ], $overrides));

    return $account;
}

/**
 * @param  list<EntryDraft>  $entries
 */
function fakeDraft(array $entries, string $currency = 'USD', string $ledgerId = 'ledger-A', ?string $reverses = null): TransactionDraft
{
    return new TransactionDraft(
        ledgerId: $ledgerId,
        reference: Reference::for('test.posting', '1'),
        currency: $currency,
        postedAt: CarbonImmutable::now(),
        description: null,
        postingType: 'TestPosting',
        entries: $entries,
        reversesTransactionId: $reverses,
    );
}

// MinimumEntries
it('MinimumEntriesValidator passes on >= 2 entries', function (): void {
    $draft = fakeDraft([
        new EntryDraft('a', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('b', EntryDirection::Credit, Money::of(100, 'USD')),
    ]);

    (new MinimumEntriesValidator)->validate($draft, new Collection);
})->throwsNoExceptions();

it('MinimumEntriesValidator rejects fewer than 2 entries', function (int $count): void {
    $entries = array_fill(0, $count, new EntryDraft('a', EntryDirection::Debit, Money::of(1, 'USD')));
    $draft = fakeDraft($entries);

    (new MinimumEntriesValidator)->validate($draft, new Collection);
})->with([0, 1])->throws(MinimumEntriesNotMetException::class);

// PositiveAmount
it('PositiveAmountValidator rejects zero amounts', function (): void {
    $draft = fakeDraft([
        new EntryDraft('a', EntryDirection::Debit, Money::of(0, 'USD')),
        new EntryDraft('b', EntryDirection::Credit, Money::of(0, 'USD')),
    ]);

    (new PositiveAmountValidator)->validate($draft, new Collection);
})->throws(NonPositiveAmountException::class);

// SingleCurrency
it('SingleCurrencyValidator rejects mixed currencies', function (): void {
    $draft = fakeDraft(entries: [
        new EntryDraft('a', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('b', EntryDirection::Credit, Money::of(100, 'EUR')),
    ], currency: 'USD');

    (new SingleCurrencyValidator)->validate($draft, new Collection);
})->throws(MixedCurrencyException::class);

// LedgerScope
it('LedgerScopeValidator rejects accounts from a foreign ledger', function (): void {
    $accounts = (new Collection([
        'a' => fakeAccount('a', ledgerId: 'ledger-A'),
        'b' => fakeAccount('b', ledgerId: 'OTHER-ledger'),
    ]));

    $draft = fakeDraft(entries: [
        new EntryDraft('a', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('b', EntryDirection::Credit, Money::of(100, 'USD')),
    ], ledgerId: 'ledger-A');

    (new LedgerScopeValidator)->validate($draft, $accounts);
})->throws(LedgerScopeViolationException::class);

it('LedgerScopeValidator rejects unknown accounts', function (): void {
    $accounts = new Collection;
    $draft = fakeDraft([
        new EntryDraft('missing', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('also-missing', EntryDirection::Credit, Money::of(100, 'USD')),
    ]);

    (new LedgerScopeValidator)->validate($draft, $accounts);
})->throws(AccountNotFoundException::class);

// AccountCurrencyMatch
it('AccountCurrencyMatchValidator rejects entries whose currency differs from the account', function (): void {
    $accounts = new Collection([
        'a' => fakeAccount('a', currency: 'USD'),
        'b' => fakeAccount('b', currency: 'EUR'),
    ]);

    $draft = fakeDraft(entries: [
        new EntryDraft('a', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('b', EntryDirection::Credit, Money::of(100, 'USD')),
    ], currency: 'USD');

    (new AccountCurrencyMatchValidator)->validate($draft, $accounts);
})->throws(AccountCurrencyMismatchException::class);

// AccountState
it('AccountStateValidator rejects archived accounts on non-reversal drafts', function (): void {
    $accounts = new Collection([
        'a' => fakeAccount('a', archived: true),
        'b' => fakeAccount('b'),
    ]);

    $draft = fakeDraft([
        new EntryDraft('a', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('b', EntryDirection::Credit, Money::of(100, 'USD')),
    ]);

    (new AccountStateValidator)->validate($draft, $accounts);
})->throws(AccountArchivedException::class);

it('AccountStateValidator permits archived accounts on reversal drafts', function (): void {
    $accounts = new Collection([
        'a' => fakeAccount('a', archived: true),
        'b' => fakeAccount('b'),
    ]);

    $draft = fakeDraft(entries: [
        new EntryDraft('a', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('b', EntryDirection::Credit, Money::of(100, 'USD')),
    ], reverses: 'tx-original-id');

    (new AccountStateValidator)->validate($draft, $accounts);
})->throwsNoExceptions();

// Balanced
it('BalancedTransactionValidator passes when Σdebits == Σcredits', function (): void {
    $draft = fakeDraft([
        new EntryDraft('a', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('b', EntryDirection::Debit, Money::of(50, 'USD')),
        new EntryDraft('c', EntryDirection::Credit, Money::of(150, 'USD')),
    ]);

    (new BalancedTransactionValidator)->validate($draft, new Collection);
})->throwsNoExceptions();

it('BalancedTransactionValidator rejects imbalanced drafts', function (): void {
    $draft = fakeDraft([
        new EntryDraft('a', EntryDirection::Debit, Money::of(100, 'USD')),
        new EntryDraft('b', EntryDirection::Credit, Money::of(50, 'USD')),
    ]);

    (new BalancedTransactionValidator)->validate($draft, new Collection);
})->throws(ImbalancedTransactionException::class);
