<?php

declare(strict_types=1);

namespace Syriable\Ledger\Validators;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use Syriable\Ledger\Data\TransactionDraft;
use Syriable\Ledger\Exceptions\PostedAtOutOfBoundsException;
use Syriable\Ledger\Recording\Clock;

/**
 * Reject postings whose business time is implausibly far in the future
 * (clock-skew protection) or earlier than an explicit historical floor.
 *
 * `posted_at` drives every balanceAsOf() query. An unvalidated future
 * timestamp silently corrupts every audit report; an unvalidated past
 * timestamp lets a buggy or hostile caller backdate entries past the
 * point where the ledger was actually opened. This validator closes
 * both holes.
 *
 * Configuration (config/ledger.php):
 *
 *   'max_clock_skew_seconds' => 300, // 5 minutes, sensible default.
 *   'historical_lower_bound' => null, // null disables the lower bound.
 *
 * The lower bound may be an ISO-8601 string or a callable returning a
 * DateTimeInterface — that lets multi-ledger apps gate by ledger
 * opening date if they need to.
 */
final class PostedAtBoundsValidator implements TransactionValidator
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ConfigRepository $config,
    ) {}

    public function validate(TransactionDraft $draft, Collection $accounts): void
    {
        $skewRaw = $this->config->get('ledger.max_clock_skew_seconds', 300);
        $skew = is_numeric($skewRaw) ? (int) $skewRaw : 300;
        if ($skew < 0) {
            $skew = 0;
        }

        $upperBound = $this->clock->now()->addSeconds($skew);
        if ($draft->postedAt > $upperBound) {
            throw PostedAtOutOfBoundsException::inFuture($draft->postedAt, $upperBound);
        }

        $lowerBoundRaw = $this->config->get('ledger.historical_lower_bound');
        $lowerBound = $this->resolveLowerBound($lowerBoundRaw);

        if ($lowerBound !== null && $draft->postedAt < $lowerBound) {
            throw PostedAtOutOfBoundsException::inPast($draft->postedAt, $lowerBound);
        }
    }

    private function resolveLowerBound(mixed $raw): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }

        if (is_callable($raw)) {
            $raw = $raw();
        }

        if ($raw instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($raw);
        }

        if (is_string($raw) && $raw !== '') {
            return CarbonImmutable::parse($raw);
        }

        return null;
    }
}
