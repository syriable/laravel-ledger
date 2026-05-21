<?php

declare(strict_types=1);

namespace Syriable\Ledger\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Syriable\Ledger\LedgerServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test runs against a pristine in-memory SQLite database, so we
        // migrate forward on setUp and never need to roll back. We deliberately
        // do NOT use loadMigrationsFrom(): it registers a teardown rollback that
        // issues `DROP TABLE transactions` while foreign keys are enforced, and
        // the transactions table has a self-referencing FK
        // (reverses_transaction_id) that blocks the drop on SQLite.
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LedgerServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
