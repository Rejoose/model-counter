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

        // Publish Filament resources
        $this->publishes([
            __DIR__ . '/Filament/Resources' => app_path('Filament/Resources'),
        ], 'counter-filament');

        // Publish everything
        $this->publishes([
            __DIR__ . '/../config/counter.php' => config_path('counter.php'),
            __DIR__ . '/Filament/Resources' => app_path('Filament/Resources'),
        ], 'counter');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCounters::class,
            ]);
        }
    }
}
