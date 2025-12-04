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
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

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
