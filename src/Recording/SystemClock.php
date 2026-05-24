<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Carbon\CarbonImmutable;

/**
 * Default Clock implementation.
 *
 * Returns CarbonImmutable::now(), which honours Carbon::setTestNow() —
 * meaning Pest/PHPUnit tests that freeze the wall clock via
 *
 *     CarbonImmutable::setTestNow('2026-05-24 10:00:00');
 *
 * automatically affect every package timestamp (recorded_at, default
 * posted_at, archived_at) for the lifetime of that override. Reset with
 * CarbonImmutable::setTestNow() at the end of the test.
 *
 * For deterministic non-Carbon control (e.g. property-based tests that
 * want injectable monotonic counters), bind your own Clock implementation
 * in a service provider — the package resolves Clock through the
 * container singleton.
 */
final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
