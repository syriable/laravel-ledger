<?php

declare(strict_types=1);

namespace Syriable\Ledger\Recording;

use Carbon\CarbonImmutable;

/**
 * Default Clock. Tests should swap this for a frozen / controllable Clock
 * by binding their own instance into the container.
 */
final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
