<?php

declare(strict_types=1);

namespace Syriable\Ledger;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Syriable\Ledger\Commands\MakePostingCommand;
use Syriable\Ledger\Commands\RebuildBalancesCommand;
use Syriable\Ledger\Commands\VerifyLedgerCommand;
use Syriable\Ledger\Recording\BalanceProjector;
use Syriable\Ledger\Recording\Clock;
use Syriable\Ledger\Recording\DatabaseBalanceProjector;
use Syriable\Ledger\Recording\DatabaseIdempotencyStore;
use Syriable\Ledger\Recording\IdempotencyStore;
use Syriable\Ledger\Recording\SystemClock;
use Syriable\Ledger\Recording\TransactionRecorder;
use Syriable\Ledger\Validators\AccountCurrencyMatchValidator;
use Syriable\Ledger\Validators\AccountStateValidator;
use Syriable\Ledger\Validators\BalancedTransactionValidator;
use Syriable\Ledger\Validators\LedgerScopeValidator;
use Syriable\Ledger\Validators\MinimumEntriesValidator;
use Syriable\Ledger\Validators\PositiveAmountValidator;
use Syriable\Ledger\Validators\SingleCurrencyValidator;
use Syriable\Ledger\Validators\TransactionValidator;
use Syriable\Ledger\Validators\ValidatorPipeline;

final class LedgerServiceProvider extends ServiceProvider
{
    /**
     * Required validators — in order. These run FIRST, always. Config can
     * only APPEND to this list, never replace, reorder, or remove.
     *
     * Order: cheapest structural checks first, semantic checks last.
     */
    private const REQUIRED_VALIDATORS = [
        MinimumEntriesValidator::class,
        PositiveAmountValidator::class,
        SingleCurrencyValidator::class,
        LedgerScopeValidator::class,
        AccountCurrencyMatchValidator::class,
        AccountStateValidator::class,
        BalancedTransactionValidator::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ledger.php', 'ledger');

        // Pluggable contracts.
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(IdempotencyStore::class, DatabaseIdempotencyStore::class);
        $this->app->singleton(BalanceProjector::class, DatabaseBalanceProjector::class);

        // Validator pipeline: required first, then config-appended additions.
        $this->app->singleton(ValidatorPipeline::class, function ($app): ValidatorPipeline {
            $validators = [];

            foreach (self::REQUIRED_VALIDATORS as $class) {
                $validators[] = $this->resolveValidator($app, $class);
            }

            /** @var list<class-string<TransactionValidator>> $extras */
            $extras = (array) config('ledger.validators', []);
            foreach ($extras as $class) {
                $validators[] = $this->resolveValidator($app, $class);
            }

            return new ValidatorPipeline($validators);
        });

        // The recorder — the only writer in the package.
        $this->app->singleton(TransactionRecorder::class);

        // LedgerManager is the backing class for the Ledger facade.
        $this->app->singleton(LedgerManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ledger.php' => config_path('ledger.php'),
            ], 'ledger-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ledger-migrations');

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            $this->commands([
                MakePostingCommand::class,
                VerifyLedgerCommand::class,
                RebuildBalancesCommand::class,
            ]);
        }
    }

    /**
     * @param  class-string  $class
     */
    private function resolveValidator(Application $app, string $class): TransactionValidator
    {
        $instance = $app->make($class);

        if (! $instance instanceof TransactionValidator) {
            throw new \InvalidArgumentException(
                "Validator {$class} must implement ".TransactionValidator::class
            );
        }

        return $instance;
    }
}
