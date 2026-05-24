<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Syriable\Ledger\Models\Transaction;

it('runs a small simulation and passes every integrity check', function (): void {
    $exit = Artisan::call('ledger:simulate', [
        '--sellers' => 5,
        '--orders' => 80,
        '--ledger' => 'sim-test',
        '--seed' => 12345,
    ]);

    expect($exit)->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('every balance matches the package')
        ->and($output)->toContain('Σ debits == Σ credits')
        ->and($output)->toContain('passed every integrity check');
});

it('is deterministic — the same seed produces the same transaction count', function (): void {
    Artisan::call('ledger:simulate', [
        '--sellers' => 4,
        '--orders' => 50,
        '--ledger' => 'sim-a',
        '--seed' => 999,
    ]);
    $countA = Transaction::query()
        ->whereHas('ledger', fn ($q) => $q->where('slug', 'sim-a'))
        ->count();

    Artisan::call('ledger:simulate', [
        '--sellers' => 4,
        '--orders' => 50,
        '--ledger' => 'sim-b',
        '--seed' => 999,
    ]);
    $countB = Transaction::query()
        ->whereHas('ledger', fn ($q) => $q->where('slug', 'sim-b'))
        ->count();

    expect($countA)->toBe($countB)
        ->and($countA)->toBeGreaterThan(0);
});

it('exercises idempotency replays during the run', function (): void {
    Artisan::call('ledger:simulate', [
        '--sellers' => 3,
        '--orders' => 60,
        '--ledger' => 'sim-replay',
        '--seed' => 42,
        '--replay-rate' => 100,   // replay every posting
    ]);

    $output = Artisan::output();

    // With a 100% replay rate the run still passes — replays must not
    // create duplicate transactions or move money.
    expect($output)->toContain('passed every integrity check')
        ->and($output)->not->toContain('Idempotency FAILED');
});

it('refuses to run twice against a non-empty ledger without --force (issue #8)', function (): void {
    // First run on a fresh slug — should succeed.
    $firstExit = Artisan::call('ledger:simulate', [
        '--sellers' => 2,
        '--orders' => 20,
        '--ledger' => 'sim-issue-8',
        '--seed' => 7,
    ]);
    expect($firstExit)->toBe(0);

    $countAfterFirst = Transaction::query()
        ->whereHas('ledger', fn ($q) => $q->where('slug', 'sim-issue-8'))
        ->count();
    expect($countAfterFirst)->toBeGreaterThan(0);

    // Second run on the same slug — must fail fast with a clear message
    // instead of corrupting the shadow and crashing on a re-reversed
    // transaction.
    $secondExit = Artisan::call('ledger:simulate', [
        '--sellers' => 2,
        '--orders' => 20,
        '--ledger' => 'sim-issue-8',
        '--seed' => 7,
    ]);

    expect($secondExit)->not->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('already contains transactions')
        ->and($output)->toContain('migrate:fresh');

    // Critically, the second run must NOT have written anything new — the
    // pre-flight check fires before bootstrap.
    $countAfterRefusal = Transaction::query()
        ->whereHas('ledger', fn ($q) => $q->where('slug', 'sim-issue-8'))
        ->count();
    expect($countAfterRefusal)->toBe($countAfterFirst);
});
