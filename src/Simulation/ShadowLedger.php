<?php

declare(strict_types=1);

namespace Syriable\Ledger\Simulation;

use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Enums\NormalBalance;

/**
 * An independent, in-memory mirror of what every account balance *should*
 * be, computed by the simulator itself without consulting the package.
 *
 * After the simulation runs, the simulator compares this shadow against
 * the package's own balance() projection. If they disagree, the package
 * has a bug — the shadow is a second opinion that does not share any code
 * with the recorder or the projector.
 *
 * @internal
 */
final class ShadowLedger
{
    /**
     * Signed balance per account id.
     *
     * @var array<string, int>
     */
    private array $balances = [];

    /**
     * Normal balance per account id, used to sign entries the same way the
     * package's BalanceCalculator does.
     *
     * @var array<string, NormalBalance>
     */
    private array $normalBalance = [];

    public function registerAccount(string $accountId, NormalBalance $normalBalance): void
    {
        $this->balances[$accountId] = 0;
        $this->normalBalance[$accountId] = $normalBalance;
    }

    /**
     * Apply one entry to the shadow, signing it by the account's normal
     * balance — exactly the rule documented in docs/08-balances.md.
     */
    public function apply(string $accountId, EntryDirection $direction, int $amount): void
    {
        $normal = $this->normalBalance[$accountId] ?? null;
        if ($normal === null) {
            throw new \RuntimeException("ShadowLedger: unknown account {$accountId}.");
        }

        $matchesNormal = $direction->value === $normal->value;
        $signed = $matchesNormal ? $amount : -$amount;

        $this->balances[$accountId] += $signed;
    }

    public function balanceOf(string $accountId): int
    {
        return $this->balances[$accountId] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->balances;
    }

    /**
     * @return list<string>
     */
    public function accountIds(): array
    {
        return array_keys($this->balances);
    }
}
