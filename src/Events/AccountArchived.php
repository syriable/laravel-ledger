<?php

declare(strict_types=1);

namespace Syriable\Ledger\Events;

use Syriable\Ledger\Models\Account;

final readonly class AccountArchived
{
    public function __construct(
        public Account $account,
    ) {}
}
