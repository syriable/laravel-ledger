<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Facades\Ledger;

beforeEach(function (): void {
    Ledger::createLedger(slug: 'archive-test', currency: 'USD');
    $this->account = Ledger::for('archive-test')->openAccount(
        code: 'archived.usd',
        type: AccountType::Asset,
        currency: 'USD',
    );
});

it('captures archived_at and archived_by when an account is archived', function (): void {
    CarbonImmutable::setTestNow('2026-05-24 09:30:00');

    try {
        $archived = Ledger::archiveAccount($this->account, actor: 'admin-user-42');
    } finally {
        CarbonImmutable::setTestNow();
    }

    expect($archived->is_archived)->toBeTrue()
        ->and($archived->archived_at)->not->toBeNull()
        ->and($archived->archived_at->format('Y-m-d H:i:s'))->toBe('2026-05-24 09:30:00')
        ->and($archived->archived_by)->toBe('admin-user-42');
});

it('records a null actor when none is supplied', function (): void {
    $archived = Ledger::archiveAccount($this->account);

    expect($archived->is_archived)->toBeTrue()
        ->and($archived->archived_at)->not->toBeNull()
        ->and($archived->archived_by)->toBeNull();
});

it('does not change archive metadata on repeat archive calls', function (): void {
    $first = Ledger::archiveAccount($this->account, actor: 'first');
    $firstAt = $first->archived_at;

    $second = Ledger::archiveAccount($this->account->fresh(), actor: 'second');

    expect($second->archived_at?->format('Y-m-d H:i:s.u'))
        ->toBe($firstAt?->format('Y-m-d H:i:s.u'))
        ->and($second->archived_by)->toBe('first');
});
