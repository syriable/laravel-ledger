<?php

declare(strict_types=1);

namespace Syriable\Ledger\Models\Concerns;

use Fiber;
use Syriable\Ledger\Exceptions\DirectWriteForbiddenException;
use WeakMap;

/**
 * Refuses any write to a financial model unless the TransactionRecorder
 * has explicitly opened a write window for the current call stack.
 *
 * The recorder opens the window via openRecorderWindow() before any write
 * and closes it in a `finally` block on the way out. Any other code path
 * that calls save()/update()/delete() on a model using this trait throws.
 *
 * Concurrency model: the window depth is tracked per Fiber via a WeakMap
 * keyed by the current Fiber object. Code running in the main fiber (the
 * default for PHP-FPM and a single Octane worker request) shares one
 * stable sentinel; coroutines (Swoole, RoadRunner with fibers, parallel
 * Octane tasks) each get their own counter and cannot share each others'
 * windows. WeakMap entries are reclaimed automatically when a fiber dies,
 * so long-running workers cannot leak window depths.
 *
 * This is the single most important application-level safety net in the
 * package; it catches the "I'll just bypass the recorder for this one
 * tiny adjustment" bug before it ever lands a single bad row. Note that
 * it does NOT protect against raw `DB::table('transactions')->insert()`
 * — see docs/12-anti-features.md.
 */
trait WritableOnlyByRecorder
{
    /**
     * Per-fiber recorder-window depths. Keyed by Fiber object (or a stable
     * sentinel for the main fiber).
     *
     * @var WeakMap<object, int>|null
     */
    private static ?WeakMap $recorderWindowDepths = null;

    /**
     * Stable sentinel representing "the main fiber" (i.e. the request thread
     * in PHP-FPM and the request thread in a single Octane worker). Created
     * lazily on first use; held as a strong reference so the WeakMap entry
     * keyed by it survives across requests within the same worker.
     */
    private static ?object $mainFiberSentinel = null;

    public static function openRecorderWindow(): void
    {
        $map = self::$recorderWindowDepths ??= new WeakMap;
        $key = self::currentFiberKey();
        $map[$key] = ($map[$key] ?? 0) + 1;
    }

    public static function closeRecorderWindow(): void
    {
        if (self::$recorderWindowDepths === null) {
            return;
        }

        $key = self::currentFiberKey();
        $current = self::$recorderWindowDepths[$key] ?? 0;

        if ($current <= 1) {
            unset(self::$recorderWindowDepths[$key]);

            return;
        }

        self::$recorderWindowDepths[$key] = $current - 1;
    }

    public static function isRecorderWindowOpen(): bool
    {
        if (self::$recorderWindowDepths === null) {
            return false;
        }

        $key = self::currentFiberKey();

        return (self::$recorderWindowDepths[$key] ?? 0) > 0;
    }

    public function save(array $options = []): bool
    {
        $this->assertRecorderWindowOpen('save');

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->assertRecorderWindowOpen('update');

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        throw DirectWriteForbiddenException::on(static::class, 'delete');
    }

    public function forceDelete(): ?bool
    {
        throw DirectWriteForbiddenException::on(static::class, 'forceDelete');
    }

    private function assertRecorderWindowOpen(string $operation): void
    {
        if (! self::isRecorderWindowOpen()) {
            throw DirectWriteForbiddenException::on(static::class, $operation);
        }
    }

    private static function currentFiberKey(): object
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            return $fiber;
        }

        return self::$mainFiberSentinel ??= new \stdClass;
    }
}
