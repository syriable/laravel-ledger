<?php

declare(strict_types=1);

use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Exceptions\DirectWriteForbiddenException;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Ledger as LedgerModel;

beforeEach(function (): void {
    $this->ledger = Ledger::createLedger(slug: 'safety-test', currency: 'USD');
    $this->account = Ledger::for('safety-test')->openAccount(
        code: 'safety.account',
        type: AccountType::Asset,
        currency: 'USD',
    );
});

it('refuses a direct save() on an Account outside the recorder window', function (): void {
    $this->account->name = 'tampered';
    $this->account->save();
})->throws(DirectWriteForbiddenException::class);

it('refuses a direct update() on a Ledger outside the recorder window', function (): void {
    $this->ledger->update(['name' => 'tampered']);
})->throws(DirectWriteForbiddenException::class);

it('refuses delete() on every financial model', function (): void {
    $this->account->delete();
})->throws(DirectWriteForbiddenException::class);

it('refuses forceDelete() too', function (): void {
    $this->account->forceDelete();
})->throws(DirectWriteForbiddenException::class);

it('reads remain freely available', function (): void {
    expect(LedgerModel::query()->where('slug', 'safety-test')->exists())->toBeTrue()
        ->and(Account::query()->where('code', 'safety.account')->exists())->toBeTrue();
});
