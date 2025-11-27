<?php

namespace Rejoose\ModelCounter;

use Illuminate\Support\ServiceProvider;
use Rejoose\ModelCounter\Console\SyncCounters;

class ModelCounterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/counter.php',
            'counter'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/counter.php' => config_path('counter.php'),
        ], 'counter-config');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCounters::class,
            ]);
        }
    }
}

