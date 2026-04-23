<?php

namespace Rejoose\ModelCounter\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Events\CounterSynced;
use Rejoose\ModelCounter\Models\ModelCounter;

class SyncCounters extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'counter:sync 
                          {--dry-run : Display what would be synced without actually syncing}
                          {--pattern= : Only sync keys matching this pattern}';

    /**
     * The console command description.
     */
    protected $description = 'Sync cached counter increments from Redis to the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $pattern = $this->option('pattern');

        $storeName = config('counter.store');
        $store = Cache::store($storeName);
        $prefix = config('counter.prefix');
        $batchSize = config('counter.sync_batch_size', 1000);

        // Check if we're using a supported store for sync
        if ($storeName === 'array') {
            $this->info('Using array cache store - sync is not needed.');
            $this->info('Array cache stores data directly in the database on each operation.');

            return self::SUCCESS;
        }

        if (! in_array($storeName, ['redis'])) {
            $this->warn("Cache store '{$storeName}' may not support sync operations.");
            $this->warn('Consider using Redis for production or array for local development.');
        }

        try {
            // Get Redis connection
            $redis = $store->connection();

            // Scan for all counter keys
            $searchPattern = $pattern
                ? "{$prefix}{$pattern}*"
                : "{$prefix}*";

            $keys = [];
            $cursor = '0';

            // Use SCAN to avoid blocking Redis
            do {
                $result = $redis->scan($cursor, [
                    'MATCH' => $searchPattern,
                    'COUNT' => $batchSize,
                ]);

                if ($result === false) {
                    break;
                }

                [$cursor, $foundKeys] = $result;
                $keys = array_merge($keys, $foundKeys);
            } while ($cursor !== '0');

            if (empty($keys)) {
                $this->info('No counters found to sync.');

                return self::SUCCESS;
            }

            $this->info('Found '.count($keys).' counter(s) to sync.');
            $synced = 0;
            $skipped = 0;
            $errors = 0;

            // Process in batches
            $batches = array_chunk($keys, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $this->info('Processing batch '.($batchIndex + 1).' of '.count($batches).'...');

                // Phase 1: Parse all keys and fetch values
                $parsedEntries = [];
                foreach ($batch as $key) {
                    try {
                        $value = $isDryRun
                            ? (int) $redis->get($key)
                            : (int) $redis->getdel($key);

                        if ($value === 0) {
                            $skipped++;

                            continue;
                        }

                        $parsed = $this->parseRedisKey($key, $prefix);
                        if ($parsed === null) {
                            $this->warn("Invalid key format: {$key}");
                            $errors++;

                            continue;
                        }

                        $parsed['value'] = $value;
                        $parsed['redis_key'] = $key;
                        $parsedEntries[] = $parsed;

                    } catch (\Exception $e) {
                        $this->error("Error processing {$key}: ".$e->getMessage());
                        $errors++;
                    }
                }

                // Phase 2: Batch-load owners grouped by model class
                $ownersByClass = [];
                foreach ($parsedEntries as $entry) {
                    $modelClass = $this->resolveModelClass($entry['owner_type']);
                    if (! $modelClass || ! class_exists($modelClass)) {
                        $this->warn("Model class not found for: {$entry['owner_type']}");
                        $errors++;

                        continue;
                    }
                    $entry['model_class'] = $modelClass;
                    $ownersByClass[$modelClass][] = $entry;
                }

                $ownerCache = [];
                foreach ($ownersByClass as $modelClass => $entries) {
                    $ownerIds = array_unique(array_column($entries, 'owner_id'));
                    $owners = $modelClass::whereIn((new $modelClass)->getKeyName(), $ownerIds)->get()->keyBy(fn ($m) => $m->getKey());
                    $ownerCache[$modelClass] = $owners;
                }

                // Phase 3: Process entries with pre-loaded owners
                foreach ($ownersByClass as $modelClass => $entries) {
                    foreach ($entries as $entry) {
                        try {
                            $owner = $ownerCache[$modelClass][$entry['owner_id']] ?? null;

                            if (! $owner) {
                                $this->warn("Owner not found: {$modelClass}#{$entry['owner_id']}");
                                $errors++;

                                continue;
                            }

                            if (! $isDryRun) {
                                ModelCounter::addDelta($owner, $entry['counter_key'], $entry['value'], $entry['interval'], $entry['period_start']);
                            }

                            $intervalInfo = $entry['interval'] ? " [{$entry['interval']->value}:{$entry['period_key_str']}]" : '';
                            $this->line("  ✓ {$modelClass}#{$entry['owner_id']} [{$entry['counter_key']}]{$intervalInfo} += {$entry['value']}");
                            $synced++;

                        } catch (\Exception $e) {
                            $this->error("Error processing {$entry['redis_key']}: ".$e->getMessage());
                            $errors++;
                        }
                    }
                }
            }

            // Summary
            $this->newLine();
            $this->info('Sync completed!');
            $this->table(
                ['Status', 'Count'],
                [
                    ['Synced', $synced],
                    ['Skipped (zero values)', $skipped],
                    ['Errors', $errors],
                ]
            );

            if ($isDryRun) {
                $this->warn('This was a dry run. No data was actually synced.');
            }

            if (! $isDryRun && config('counter.events', false)) {
                event(new CounterSynced($synced, $skipped, $errors));
            }

            return $errors > 0 ? self::FAILURE : self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Parse a Redis key into its components.
     *
     * @return array{owner_type: string, owner_id: string, counter_key: string, interval: ?Interval, period_start: ?Carbon, period_key_str: ?string}|null
     */
    protected function parseRedisKey(string $key, string $prefix): ?array
    {
        $parsedKey = str_replace($prefix, '', $key);
        $parts = explode(':', $parsedKey);

        if (count($parts) < 3) {
            return null;
        }

        $interval = null;
        $periodStart = null;
        $periodKeyStr = null;

        // Check if the second-to-last part is a valid interval
        if (count($parts) >= 5) {
            $possibleInterval = Interval::tryFrom($parts[count($parts) - 2]);
            if ($possibleInterval !== null) {
                $periodKeyStr = array_pop($parts);
                array_pop($parts); // remove interval
                $interval = $possibleInterval;

                $periodStart = $this->parsePeriodKey($interval, $periodKeyStr);

                if ($periodStart === null) {
                    return null;
                }
            }
        }

        if (count($parts) < 3) {
            return null;
        }

        $counterKey = array_pop($parts);
        $ownerId = array_pop($parts);
        $ownerType = implode(':', $parts);

        return [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'counter_key' => $counterKey,
            'interval' => $interval,
            'period_start' => $periodStart,
            'period_key_str' => $periodKeyStr,
        ];
    }

    /**
     * Resolve the full model class name from the owner type.
     */
    protected function resolveModelClass(string $ownerType): ?string
    {
        // First, check Laravel's morph map (supports custom aliases)
        $morphedModel = Relation::getMorphedModel($ownerType);
        if ($morphedModel && class_exists($morphedModel)) {
            return $morphedModel;
        }

        // Convert dot-notation morph class back to namespace (e.g., app.models.user → App\Models\User)
        $fromDotNotation = str_replace('.', '\\', $ownerType);
        $fromDotNotation = implode('\\', array_map('ucfirst', explode('\\', $fromDotNotation)));

        $attempts = [
            $fromDotNotation,
            'App\\Models\\'.ucfirst($ownerType),
            'App\\Models\\'.$ownerType,
            $ownerType, // Already fully qualified
        ];

        foreach ($attempts as $attempt) {
            if (class_exists($attempt)) {
                return $attempt;
            }
        }

        return null;
    }

    /**
     * Parse a period key back to a Carbon date.
     */
    protected function parsePeriodKey(Interval $interval, string $periodKey): ?Carbon
    {
        try {
            return match ($interval) {
                Interval::Day => Carbon::createFromFormat('Y-m-d', $periodKey)?->startOfDay(),
                Interval::Week => $this->parseIsoWeek($periodKey),
                Interval::Month => Carbon::createFromFormat('Y-m', $periodKey)?->startOfMonth(),
                Interval::Quarter => $this->parseQuarter($periodKey),
                Interval::Year => Carbon::createFromFormat('Y', $periodKey)?->startOfYear(),
            };
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse ISO week format (e.g., "2024-W01") to Carbon.
     */
    protected function parseIsoWeek(string $weekKey): ?Carbon
    {
        if (preg_match('/^(\d{4})-W?(\d{2})$/', $weekKey, $matches)) {
            return Carbon::now()
                ->setISODate((int) $matches[1], (int) $matches[2])
                ->startOfWeek();
        }

        return null;
    }

    /**
     * Parse quarter format (e.g., "2024-Q1") to Carbon.
     */
    protected function parseQuarter(string $quarterKey): ?Carbon
    {
        if (preg_match('/^(\d{4})-Q(\d)$/', $quarterKey, $matches)) {
            $year = (int) $matches[1];
            $quarter = (int) $matches[2];
            $month = ($quarter - 1) * 3 + 1;

            return Carbon::create($year, $month, 1)->startOfMonth();
        }

        return null;
    }
}
