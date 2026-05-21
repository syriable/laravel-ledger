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
