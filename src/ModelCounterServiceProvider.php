<?php

namespace Rejoose\ModelCounter;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Rejoose\ModelCounter\Console\SyncCounters;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Traits\HasCounters;

class ModelCounterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/counter.php',
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
            __DIR__.'/../config/counter.php' => config_path('counter.php'),
        ], 'counter-config');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Publish Filament resources
        $this->publishes([
            __DIR__.'/Filament/Resources' => app_path('Filament/Resources'),
        ], 'counter-filament');

        // Publish everything
        $this->publishes([
            __DIR__.'/../config/counter.php' => config_path('counter.php'),
            __DIR__.'/Filament/Resources' => app_path('Filament/Resources'),
        ], 'counter');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCounters::class,
            ]);
        }

        // Register relation macro
        Relation::macro('recount', function (?string $key = null, ?Interval $interval = null, int $periods = 1, ?\Carbon\Carbon $fromDate = null, string $dateColumn = 'created_at') {
            /** @var Relation $this */
            $model = $this->getParent();

            if (! in_array(HasCounters::class, class_uses_recursive($model))) {
                throw new \RuntimeException('Parent model does not use HasCounters trait.');
            }

            $key = $key ?? $this->getRelated()->getTable();
            $relation = $this;

            if ($interval) {
                return $model->recountCounterPeriods(
                    $key,
                    $interval,
                    function ($start, $end) use ($dateColumn, $relation) {
                        return $relation->getQuery()
                            ->clone()
                            ->whereBetween($dateColumn, [$start, $end])
                            ->count();
                    },
                    $periods,
                    $fromDate
                );
            }

            return $model->recountCounter($key, fn () => $this->count(), $interval);
        });
    }
}
