<?php

namespace Rejoose\ModelCounter\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Rejoose\ModelCounter\Contracts\DefinesCounters;
use Rejoose\ModelCounter\Traits\HasCounters;

/**
 * Recount every counter declared by a DefinesCounters model from its
 * source-of-truth closures. Chunks over the model's records so a table with
 * millions of owners doesn't load into memory. Each app previously hand-rolled
 * this loop; it lives in the package so Rejoose-app and e-papi share one path.
 */
class RecountCounters extends Command
{
    protected $signature = 'counter:recount
                          {model : Model class (FQCN) or morph-map alias implementing DefinesCounters}
                          {--id=* : Only recount these owner ids (repeatable)}
                          {--from= : Start of the interval range (Y-m-d); defaults to --to}
                          {--to= : End of the interval range (Y-m-d); defaults to now}
                          {--chunk=500 : Records per chunk}';

    protected $description = 'Recount a model\'s declared counters from their source-of-truth definitions';

    public function handle(): int
    {
        $modelClass = $this->resolveModelClass((string) $this->argument('model'));

        if ($modelClass === null) {
            $this->error("Could not resolve a model class from '{$this->argument('model')}'.");

            return self::FAILURE;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->error("{$modelClass} is not an Eloquent model.");

            return self::FAILURE;
        }

        if (! in_array(DefinesCounters::class, class_implements($modelClass) ?: [], true)
            || ! in_array(HasCounters::class, class_uses_recursive($modelClass), true)) {
            $this->error("{$modelClass} must implement DefinesCounters and use the HasCounters trait.");

            return self::FAILURE;
        }

        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : null;
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to')) : null;
        $ids = (array) $this->option('id');
        $chunk = max(1, (int) $this->option('chunk'));

        /** @var Builder<Model> $query */
        $query = $modelClass::query();
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $processed = 0;

        $query->chunkById($chunk, function ($records) use (&$processed, $from, $to): void {
            foreach ($records as $record) {
                // recountAllCounters() comes from the HasCounters trait, which
                // PHPStan can't see through the Model base class (same pattern
                // as the Relation::recount macro in the service provider).
                /** @phpstan-ignore-next-line */
                $record->recountAllCounters($from, $to);
                $processed++;
            }

            $this->line("  recounted {$processed} record(s)...");
        });

        $this->info("Recount complete. Processed {$processed} {$modelClass} record(s).");

        return self::SUCCESS;
    }

    /**
     * Resolve a morph-map alias or class name to a fully-qualified class.
     */
    protected function resolveModelClass(string $model): ?string
    {
        $morphed = Relation::getMorphedModel($model);
        if ($morphed !== null && class_exists($morphed)) {
            return $morphed;
        }

        return class_exists($model) ? $model : null;
    }
}
