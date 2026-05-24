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

        // SQLite tests run against a fresh in-memory database per test, so a
        // plain `migrate` suffices. For real-DB CI (MySQL, Postgres), the
        // schema persists between tests so we explicitly migrate:fresh to
        // guarantee a clean slate.
        //
        // We deliberately do NOT use loadMigrationsFrom(): it registers a
        // teardown rollback that issues `DROP TABLE transactions` while
        // foreign keys are enforced, and the transactions table has a
        // self-referencing FK (reverses_transaction_id) that blocks the
        // drop on SQLite.
        $command = (getenv('DB_DRIVER') ?: 'sqlite') === 'sqlite' ? 'migrate' : 'migrate:fresh';
        $this->artisan($command, ['--database' => 'testing'])->run();
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
        $app['config']->set('database.connections.testing', $this->resolveTestingConnection());
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolveTestingConnection(): array
    {
        $driver = getenv('DB_DRIVER') ?: 'sqlite';

        return match ($driver) {
            'mysql', 'mariadb' => [
                'driver' => $driver,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 3306),
                'database' => getenv('DB_DATABASE') ?: 'ledger_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 5432),
                'database' => getenv('DB_DATABASE') ?: 'ledger_test',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'sslmode' => 'prefer',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }
}
