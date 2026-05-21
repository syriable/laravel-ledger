<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Carbon\CarbonImmutable;

/**
 * Clock — the only allowed source of "now".
 *
 * Calls to CarbonImmutable::now() and Carbon::now() are forbidden inside the
 * package outside this contract. This is what makes deterministic replay,
 * tests, and audit reconstruction possible.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
