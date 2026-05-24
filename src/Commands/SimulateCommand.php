<?php

declare(strict_types=1);

namespace Syriable\Ledger\Commands;

use Illuminate\Console\Command;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Syriable\Ledger\Data\PostingResult;
use Syriable\Ledger\Enums\AccountType;
use Syriable\Ledger\Enums\EntryDirection;
use Syriable\Ledger\Facades\Ledger;
use Syriable\Ledger\Models\Account;
use Syriable\Ledger\Models\Entry;
use Syriable\Ledger\Models\Ledger as LedgerModel;
use Syriable\Ledger\Models\Transaction;
use Syriable\Ledger\Postings\Posting;
use Syriable\Ledger\Simulation\Postings\SimOrderCompletedPosting;
use Syriable\Ledger\Simulation\Postings\SimOrderPaidPosting;
use Syriable\Ledger\Simulation\Postings\SimPartialRefundPosting;
use Syriable\Ledger\Simulation\Postings\SimPayoutPosting;
use Syriable\Ledger\Simulation\ShadowLedger;
use Syriable\Ledger\ValueObjects\Money;

/**
 * php artisan ledger:simulate
 *
 * Drives a realistic marketplace lifecycle through the real public API at
 * volume, then verifies the result three independent ways:
 *
 *   1. An in-memory ShadowLedger (computed without the package) must match
 *      every balance() the package reports.
 *   2. Idempotency replays must return wasReplayed = true and create no
 *      duplicate transactions.
 *   3. `ledger:verify` must pass.
 *
 * This is a load-testing and confidence tool. It is safe to run against a
 * scratch database; it is NOT meant to run against production data.
 */
final class SimulateCommand extends Command
{
    protected $signature = 'ledger:simulate
        {--sellers=50 : Number of seller accounts to create}
        {--orders=2000 : Number of orders to simulate}
        {--currency=USD : ISO 4217 currency for the simulation}
        {--ledger=sim-main : Slug of the ledger to simulate into}
        {--seed=20260101 : RNG seed for a reproducible run}
        {--complete-rate=85 : Percent of paid orders that complete}
        {--refund-rate=8 : Percent of completed orders that get a partial refund}
        {--reversal-rate=3 : Percent of paid orders reversed (treated as a mistake)}
        {--payout-rate=60 : Percent of sellers that request a payout at the end}
        {--replay-rate=5 : Percent of postings deliberately re-posted to test idempotency}
        {--force : Run even if the target ledger already has transactions (NOT recommended)}';

    protected $description = 'Run a realistic marketplace simulation and verify ledger integrity at volume';

    private ShadowLedger $shadow;

    /** @var array<string, Account> Platform accounts, by code. */
    private array $platform = [];

    /** @var array<int, array{escrow: Account, available: Account}> */
    private array $sellers = [];

    private int $postingsAttempted = 0;

    private int $postingsReplayed = 0;

    private int $reversalsDone = 0;

    private Randomizer $rng;

    public function handle(): int
    {
        $sellersCount = max(1, (int) $this->option('sellers'));
        $ordersCount = max(1, (int) $this->option('orders'));
        $currency = strtoupper($this->stringOption('currency'));
        $ledgerSlug = $this->stringOption('ledger');
        $seed = (int) $this->option('seed');

        // Local, seeded RNG — does not touch the global mt_rand() state.
        $this->rng = new Randomizer(new Mt19937($seed));

        $this->components->info("Ledger simulation — {$sellersCount} sellers, {$ordersCount} orders, seed {$seed}");

        // Refuse to run on a non-empty ledger. Idempotent references would
        // make every posting on the second run report wasReplayed=true; the
        // shadow would drift out of sync with the projection within seconds
        // and the random-reversal branch would attempt to reverse a
        // transaction that was already reversed in the prior run (issue #8).
        if (! $this->preflightLedgerIsEmpty($ledgerSlug)) {
            return self::FAILURE;
        }

        $this->shadow = new ShadowLedger;

        $startedAt = microtime(true);

        $this->bootstrapLedger($ledgerSlug, $currency, $sellersCount);
        $this->runOrders($ledgerSlug, $currency, $ordersCount);
        $this->runPayouts($ledgerSlug, $currency);

        $elapsed = microtime(true) - $startedAt;

        $this->reportThroughput($elapsed);

        $ok = $this->verifyShadowMatchesProjection()
            && $this->verifyZeroSum($ledgerSlug)
            && $this->runLedgerVerify($ledgerSlug);

        if (! $ok) {
            $this->components->error('Simulation FAILED — see the checks above.');

            return self::FAILURE;
        }

        $this->components->info('Simulation passed every integrity check.');

        return self::SUCCESS;
    }

    private function preflightLedgerIsEmpty(string $ledgerSlug): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        // If a ledger with this slug already exists, refuse unless it has no
        // transactions. The simulator uses deterministic, idempotent
        // references and a fixed RNG seed; running again over the prior
        // run's rows would either succeed silently with a corrupt shadow
        // or crash on the random reversal of an already-reversed
        // transaction (issue #8).
        $ledger = LedgerModel::query()
            ->where('slug', $ledgerSlug)
            ->first();

        if ($ledger === null) {
            return true;
        }

        $existingTransactions = Transaction::query()
            ->where('ledger_id', $ledger->id)
            ->limit(1)
            ->exists();

        if (! $existingTransactions) {
            return true;
        }

        $this->components->error(
            "Ledger '{$ledgerSlug}' already contains transactions. The simulator ".
            'uses deterministic references and would replay them on a re-run; '.
            'the shadow check would drift and the random reversal branch '.
            "would crash on a re-reversed transaction.\n\n".
            'Run `php artisan migrate:fresh` (against your scratch database, '.
            'never production), or pass --ledger=<another-slug>, or --force '.
            'if you really know what you are doing.'
        );

        return false;
    }

    private function bootstrapLedger(string $slug, string $currency, int $sellersCount): void
    {
        $this->components->task('Bootstrapping ledger and accounts', function () use ($slug, $currency, $sellersCount): void {
            $ledger = Ledger::createLedger(slug: $slug, currency: $currency, name: 'Simulation Ledger');
            $scope = Ledger::for($slug);

            $platformAccounts = [
                'platform.cash.usd' => AccountType::Asset,
                'platform.revenue.commission.usd' => AccountType::Revenue,
            ];

            foreach ($platformAccounts as $code => $type) {
                $account = $scope->openAccount(code: $code, type: $type, currency: $currency);
                $this->platform[$code] = $account;
                $this->shadow->registerAccount($account->id, $account->normal_balance);
            }

            for ($i = 1; $i <= $sellersCount; $i++) {
                $escrow = $scope->openAccount(
                    code: "seller.{$i}.escrow.usd",
                    type: AccountType::Liability,
                    currency: $currency,
                );
                $available = $scope->openAccount(
                    code: "seller.{$i}.available.usd",
                    type: AccountType::Liability,
                    currency: $currency,
                );

                $this->shadow->registerAccount($escrow->id, $escrow->normal_balance);
                $this->shadow->registerAccount($available->id, $available->normal_balance);

                $this->sellers[$i] = ['escrow' => $escrow, 'available' => $available];
            }
        });
    }

    private function runOrders(string $slug, string $currency, int $ordersCount): void
    {
        $completeRate = (int) $this->option('complete-rate');
        $refundRate = (int) $this->option('refund-rate');
        $reversalRate = (int) $this->option('reversal-rate');

        $bar = $this->output->createProgressBar($ordersCount);
        $bar->start();

        for ($n = 1; $n <= $ordersCount; $n++) {
            $sellerIndex = $this->rng->getInt(1, count($this->sellers));
            $seller = $this->sellers[$sellerIndex];

            // Realistic order amounts: $5.00 - $500.00 in minor units.
            $total = $this->rng->getInt(500, 50_000);
            // Commission is 10-20% of the total.
            $commission = (int) floor($total * $this->rng->getInt(10, 20) / 100);
            $sellerNet = $total - $commission;

            $orderId = "order-{$n}";

            $paid = $this->postOrderPaid($slug, $currency, $orderId, $seller, $total, $sellerNet, $commission);

            // If the paid posting was an unexpected replay (the prior run's
            // row still in the DB), do NOT cascade into reverse/complete/refund
            // for this order. The prior run already picked its branches; doing
            // it again would either double-book the shadow or hit
            // ReversalNotAllowedException on the random reversal roll
            // (issue #8). The pre-flight check above normally prevents this,
            // but this defence keeps the rest of the run consistent if it
            // somehow leaks through (e.g. --force).
            if ($paid->wasReplayed) {
                $bar->advance();

                continue;
            }

            // Some paid orders are reversed (treated as a mistake).
            if ($this->roll($reversalRate)) {
                $this->reverse($paid->transaction);
                $bar->advance();

                continue; // a reversed order does not continue its lifecycle
            }

            // Most orders complete: escrow -> available.
            if ($this->roll($completeRate)) {
                $this->postOrderCompleted($slug, $currency, $orderId, $seller, $sellerNet);

                // A few completed orders get a partial refund.
                if ($this->roll($refundRate)) {
                    $refundAmount = (int) floor($sellerNet * $this->rng->getInt(10, 50) / 100);
                    $commissionClaw = (int) floor($commission * $this->rng->getInt(10, 50) / 100);

                    if ($refundAmount > 0 && $commissionClaw > 0) {
                        $this->postPartialRefund($slug, $currency, $orderId, $seller, $refundAmount, $commissionClaw);
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
    }

    private function runPayouts(string $slug, string $currency): void
    {
        $payoutRate = (int) $this->option('payout-rate');

        $this->components->task('Running end-of-run payouts', function () use ($slug, $currency, $payoutRate): void {
            foreach ($this->sellers as $index => $seller) {
                if (! $this->roll($payoutRate)) {
                    continue;
                }

                // Pay out whatever is currently available, per the shadow.
                $available = $this->shadow->balanceOf($seller['available']->id);
                if ($available <= 0) {
                    continue;
                }

                $this->postPayout($slug, $currency, "payout-{$index}", $seller, $available);
            }
        });
    }

    /* ---------------------------------------------------------------------
     | Posting helpers — each posts via the real API, mirrors the shadow,
     | and optionally exercises an idempotency replay.
     * ------------------------------------------------------------------- */

    /**
     * @param  array{escrow: Account, available: Account}  $seller
     */
    private function postOrderPaid(
        string $slug,
        string $currency,
        string $orderId,
        array $seller,
        int $total,
        int $sellerNet,
        int $commission,
    ): PostingResult {
        $make = fn (): SimOrderPaidPosting => new SimOrderPaidPosting(
            orderId: $orderId,
            ledgerSlug: $slug,
            cash: $this->platform['platform.cash.usd'],
            sellerEscrow: $seller['escrow'],
            commissionRevenue: $this->platform['platform.revenue.commission.usd'],
            total: Money::of($total, $currency),
            sellerNet: Money::of($sellerNet, $currency),
            commission: Money::of($commission, $currency),
        );

        $result = $this->postOnce($make());

        // Mirror into the shadow only on a genuine first post. A replay must
        // not move money — see postOnce() and the maybeReplay() docblock.
        if (! $result->wasReplayed) {
            $this->shadow->apply($this->platform['platform.cash.usd']->id, EntryDirection::Debit, $total);
            $this->shadow->apply($seller['escrow']->id, EntryDirection::Credit, $sellerNet);
            $this->shadow->apply($this->platform['platform.revenue.commission.usd']->id, EntryDirection::Credit, $commission);

            $this->maybeReplay($make());
        }

        return $result;
    }

    /**
     * @param  array{escrow: Account, available: Account}  $seller
     */
    private function postOrderCompleted(
        string $slug,
        string $currency,
        string $orderId,
        array $seller,
        int $sellerNet,
    ): void {
        $make = fn (): SimOrderCompletedPosting => new SimOrderCompletedPosting(
            orderId: $orderId,
            ledgerSlug: $slug,
            sellerEscrow: $seller['escrow'],
            sellerAvailable: $seller['available'],
            sellerNet: Money::of($sellerNet, $currency),
        );

        $result = $this->postOnce($make());

        if (! $result->wasReplayed) {
            $this->shadow->apply($seller['escrow']->id, EntryDirection::Debit, $sellerNet);
            $this->shadow->apply($seller['available']->id, EntryDirection::Credit, $sellerNet);

            $this->maybeReplay($make());
        }
    }

    /**
     * @param  array{escrow: Account, available: Account}  $seller
     */
    private function postPartialRefund(
        string $slug,
        string $currency,
        string $orderId,
        array $seller,
        int $refundAmount,
        int $commissionClaw,
    ): void {
        $make = fn (): SimPartialRefundPosting => new SimPartialRefundPosting(
            refundId: $orderId,
            ledgerSlug: $slug,
            sellerEscrow: $seller['escrow'],
            commissionRevenue: $this->platform['platform.revenue.commission.usd'],
            platformCash: $this->platform['platform.cash.usd'],
            refundAmount: Money::of($refundAmount, $currency),
            commissionClaw: Money::of($commissionClaw, $currency),
        );

        $result = $this->postOnce($make());

        if (! $result->wasReplayed) {
            $this->shadow->apply($seller['escrow']->id, EntryDirection::Debit, $refundAmount);
            $this->shadow->apply($this->platform['platform.revenue.commission.usd']->id, EntryDirection::Debit, $commissionClaw);
            $this->shadow->apply($this->platform['platform.cash.usd']->id, EntryDirection::Credit, $refundAmount + $commissionClaw);

            $this->maybeReplay($make());
        }
    }

    /**
     * @param  array{escrow: Account, available: Account}  $seller
     */
    private function postPayout(
        string $slug,
        string $currency,
        string $payoutId,
        array $seller,
        int $amount,
    ): void {
        $make = fn (): SimPayoutPosting => new SimPayoutPosting(
            payoutId: $payoutId,
            ledgerSlug: $slug,
            sellerAvailable: $seller['available'],
            platformCash: $this->platform['platform.cash.usd'],
            amount: Money::of($amount, $currency),
        );

        $result = $this->postOnce($make());

        if (! $result->wasReplayed) {
            $this->shadow->apply($seller['available']->id, EntryDirection::Debit, $amount);
            $this->shadow->apply($this->platform['platform.cash.usd']->id, EntryDirection::Credit, $amount);

            $this->maybeReplay($make());
        }
    }

    /* ---------------------------------------------------------------------
     | Recorder interaction
     * ------------------------------------------------------------------- */

    private function postOnce(Posting $posting): PostingResult
    {
        $this->postingsAttempted++;
        $result = Ledger::post($posting);

        if ($result->wasReplayed) {
            // A genuine first post should never report a replay. If it does,
            // a reference is colliding — surface it loudly.
            $this->components->warn(
                "Unexpected replay on first post of reference {$posting->reference()}."
            );
        }

        return $result;
    }

    /**
     * Deliberately re-post the same operation to confirm idempotency: the
     * recorder must return wasReplayed = true and create no new rows. The
     * shadow is NOT touched here — a replay must not move money.
     */
    private function maybeReplay(Posting $posting): void
    {
        if (! $this->roll((int) $this->option('replay-rate'))) {
            return;
        }

        $this->postingsAttempted++;
        $result = Ledger::post($posting);
        $this->postingsReplayed++;

        if (! $result->wasReplayed) {
            $this->components->error(
                "Idempotency FAILED: re-posting reference {$posting->reference()} ".
                'did not report a replay.'
            );
        }
    }

    private function reverse(Transaction $transaction): void
    {
        $result = Ledger::reverse($transaction, reason: 'simulation reversal');
        $this->reversalsDone++;

        // A reversal inverts every entry of the original. Mirror that into
        // the shadow by replaying the original's entries with the opposite
        // direction.
        foreach ($transaction->entries as $entry) {
            /** @var Entry $entry */
            $this->shadow->apply(
                $entry->account_id,
                $entry->direction->opposite(),
                $entry->amount,
            );
        }

        if ($result->wasReplayed) {
            // Reversing the same transaction twice should never reach here —
            // ReversalNotAllowedException would have been thrown first.
            $this->components->warn('Unexpected replay on a reversal.');
        }
    }

    /* ---------------------------------------------------------------------
     | Verification
     * ------------------------------------------------------------------- */

    private function verifyShadowMatchesProjection(): bool
    {
        $mismatches = 0;

        foreach ($this->shadow->accountIds() as $accountId) {
            /** @var Account|null $account */
            $account = Account::query()->find($accountId);
            if ($account === null) {
                $this->components->error("Account {$accountId} vanished from the database.");
                $mismatches++;

                continue;
            }

            $expected = $this->shadow->balanceOf($accountId);
            $projected = $account->balance();
            $aggregated = $account->balanceAsOf(now());

            if ($projected !== $expected) {
                $this->components->error(
                    "Projection mismatch on {$account->code}: ".
                    "shadow={$expected} projection={$projected}"
                );
                $mismatches++;
            }

            if ($aggregated !== $expected) {
                $this->components->error(
                    "Aggregation mismatch on {$account->code}: ".
                    "shadow={$expected} balanceAsOf={$aggregated}"
                );
                $mismatches++;
            }
        }

        if ($mismatches > 0) {
            $this->components->error("Shadow check: {$mismatches} mismatch(es).");

            return false;
        }

        $this->components->info('Shadow check: every balance matches the package (projection and aggregation).');

        return true;
    }

    private function verifyZeroSum(string $slug): bool
    {
        $ledger = Ledger::for($slug)->ledger;

        $debits = (int) Entry::query()
            ->where('ledger_id', $ledger->id)
            ->where('direction', EntryDirection::Debit->value)
            ->sum('amount');

        $credits = (int) Entry::query()
            ->where('ledger_id', $ledger->id)
            ->where('direction', EntryDirection::Credit->value)
            ->sum('amount');

        if ($debits !== $credits) {
            $this->components->error("Zero-sum check FAILED: debits={$debits} credits={$credits}");

            return false;
        }

        $this->components->info("Zero-sum check: Σ debits == Σ credits == {$debits}.");

        return true;
    }

    private function runLedgerVerify(string $slug): bool
    {
        $exit = $this->call('ledger:verify', ['--ledger' => $slug]);

        if ($exit !== self::SUCCESS) {
            $this->components->error('ledger:verify reported drift.');

            return false;
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     | Reporting & utilities
     * ------------------------------------------------------------------- */

    private function reportThroughput(float $elapsed): void
    {
        $transactions = Transaction::query()->count();
        $entries = Entry::query()->count();
        $accounts = Account::query()->count();
        $rate = $elapsed > 0 ? $this->postingsAttempted / $elapsed : 0.0;

        $this->components->twoColumnDetail('Accounts opened', (string) $accounts);
        $this->components->twoColumnDetail('Postings attempted', (string) $this->postingsAttempted);
        $this->components->twoColumnDetail('  of which replays', (string) $this->postingsReplayed);
        $this->components->twoColumnDetail('Reversals', (string) $this->reversalsDone);
        $this->components->twoColumnDetail('Transactions persisted', (string) $transactions);
        $this->components->twoColumnDetail('Entries persisted', (string) $entries);
        $this->components->twoColumnDetail('Elapsed', number_format($elapsed, 2).' s');
        $this->components->twoColumnDetail('Throughput', number_format($rate, 1).' postings/s');
        $this->newLine();
    }

    /**
     * Return true with the given percentage probability (0-100).
     */
    private function roll(int $percent): bool
    {
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        return $this->rng->getInt(1, 100) <= $percent;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Option [{$name}] must be a non-empty string.");
        }

        return $value;
    }
}
