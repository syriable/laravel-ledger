<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

use DateTimeInterface;

final class PostedAtOutOfBoundsException extends LedgerException
{
    public static function inFuture(DateTimeInterface $postedAt, DateTimeInterface $upperBound): self
    {
        return new self(
            "Posting posted_at={$postedAt->format('Y-m-d H:i:s.uP')} is beyond the allowed future window ".
            "(upper bound {$upperBound->format('Y-m-d H:i:s.uP')}). ".
            'Increase ledger.max_clock_skew_seconds if your callers legitimately backdate from the future.'
        );
    }

    public static function inPast(DateTimeInterface $postedAt, DateTimeInterface $lowerBound): self
    {
        return new self(
            "Posting posted_at={$postedAt->format('Y-m-d H:i:s.uP')} is before the configured lower bound ".
            "(lower bound {$lowerBound->format('Y-m-d H:i:s.uP')}). ".
            'Adjust ledger.historical_lower_bound or correct the Posting.'
        );
    }
}
