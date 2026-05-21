<?php

declare(strict_types=1);

namespace Syriable\Ledger\Models\Concerns;

use Syriable\Ledger\Exceptions\DirectWriteForbiddenException;

/**
 * Refuses any write to a financial model unless the TransactionRecorder
 * has explicitly opened a write window for the current call stack.
 *
 * The recorder opens the window via openRecorderWindow() before any write
 * and closes it in a `finally` block on the way out. Any other code path
 * that calls save()/update()/delete() on a model using this trait throws.
 *
 * This is the single most important safety net in the package: it catches
 * the "I'll just bypass the recorder for this one tiny adjustment" bug
 * before it ever lands a single bad row.
 */
trait WritableOnlyByRecorder
{
    private static int $recorderWindowDepth = 0;

    public static function openRecorderWindow(): void
    {
        self::$recorderWindowDepth++;
    }

    public static function closeRecorderWindow(): void
    {
        if (self::$recorderWindowDepth > 0) {
            self::$recorderWindowDepth--;
        }
    }

    public static function isRecorderWindowOpen(): bool
    {
        return self::$recorderWindowDepth > 0;
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
}
