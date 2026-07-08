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

        // Create the tables the test suite's owner models attach to. This runs
        // in the migration phase, *before* RefreshDatabase opens its per-test
        // transaction. That ordering matters on MySQL, where DDL auto-commits:
        // creating these tables inside setUp()/beforeEach() (as the suite used
        // to) would commit the open transaction and destroy test isolation.
        // SQLite tolerated it because its DDL is transactional; MySQL does not.
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $create = static function (string $name, \Closure $definition) use ($schema): void {
            if (! $schema->hasTable($name)) {
                $schema->create($name, $definition);
            }
        };

        $simpleOwner = static function ($table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        };

        // Owner tables that are just id + name + timestamps.
        foreach ([
            'test_users',
            'bulk_test_users',
            'bulk_set_test_users',
            'defined_counter_users',
            'global_test_owners',
            'prune_test_users',
            'sync_test_users',
            'recount_interval_test_users',
            'relation_test_users',
        ] as $name) {
            $create($name, $simpleOwner);
        }

        $create('recount_subjects', function ($table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('things_count')->default(0);
            $table->timestamps();
        });

        $create('recount_plain_models', function ($table): void {
            $table->id();
            $table->timestamps();
        });

        $create('recount_interval_test_logins', function ($table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });

        $create('relation_test_posts', function ($table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->timestamps();
        });
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
