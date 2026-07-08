<?php

namespace Rejoose\ModelCounter\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Rejoose\ModelCounter\ModelCounterServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ModelCounterServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // Package tables plus the suite's owner/relation tables. Both go through
        // the migrator so RefreshDatabase's `migrate:fresh` recreates them on
        // every driver — creating the owner tables imperatively in
        // setUp()/beforeEach() broke on MySQL (migrate:fresh drops non-migration
        // tables, and MySQL's auto-committing DDL destroyed transaction isolation).
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Default to SQLite :memory: for a fast, dependency-free run. CI also
        // runs the suite with DB_CONNECTION=mysql against a real server: SQLite
        // is permissive about column types and arithmetic and masked the
        // 2026-06 unsigned-count regression, so the schema/sync arithmetic must
        // also be exercised on MySQL.
        if (env('DB_CONNECTION') === 'mysql') {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'model_counter_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        }

        // Use array cache with direct mode for testing (no Redis required)
        $app['config']->set('cache.default', 'array');
        $app['config']->set('counter.store', 'array');
        $app['config']->set('counter.direct', true);
    }

    /**
     * Configure the test to use Redis instead of array cache.
     * Useful for testing the Redis-specific sync functionality.
     */
    protected function useRedisCache(): void
    {
        config([
            'cache.default' => 'redis',
            'counter.store' => 'redis',
            'counter.direct' => false,
        ]);
    }
}
