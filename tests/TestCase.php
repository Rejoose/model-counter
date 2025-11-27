<?php

namespace Rejoose\ModelCounter\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rejoose\ModelCounter\ModelCounterServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ModelCounterServiceProvider::class,
        ];
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

        // Setup Redis for testing
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('counter.store', 'redis');
    }
}

