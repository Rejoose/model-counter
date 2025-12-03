<?php

namespace Rejoose\ModelCounter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Rejoose\ModelCounter\Enums\Interval;
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

            $this->info('Found ' . count($keys) . ' counter(s) to sync.');
            $synced = 0;
            $skipped = 0;
            $errors = 0;

            // Process in batches
            $batches = array_chunk($keys, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $this->info('Processing batch ' . ($batchIndex + 1) . ' of ' . count($batches) . '...');

                foreach ($batch as $key) {
                    try {
                        // Get the value and delete atomically
                        $value = $isDryRun
                            ? (int) $redis->get($key)
                            : (int) $redis->getdel($key);

                        if ($value === 0) {
                            $skipped++;

                            continue;
                        }

                        // Parse the key
                        $parsedKey = str_replace($prefix, '', $key);
                        $parts = explode(':', $parsedKey);

                        // Key format can be:
                        // - owner_type:owner_id:counter_key (3 parts, no interval)
                        // - owner_type:owner_id:counter_key:interval:period_key (5 parts, with interval)
                        if (count($parts) !== 3 && count($parts) !== 5) {
                            $this->warn("Invalid key format: {$key}");
                            $errors++;

                            continue;
                        }

                        $ownerType = $parts[0];
                        $ownerId = $parts[1];
                        $counterKey = $parts[2];
                        $interval = null;
                        $periodStart = null;

                        if (count($parts) === 5) {
                            $intervalValue = $parts[3];
                            $interval = Interval::tryFrom($intervalValue);

                            if ($interval === null) {
                                $this->warn("Invalid interval: {$intervalValue} in key: {$key}");
                                $errors++;

                                continue;
                            }

                            // Parse period key back to date
                            $periodStart = $this->parsePeriodKey($interval, $parts[4]);

                            if ($periodStart === null) {
                                $this->warn("Invalid period key: {$parts[4]} in key: {$key}");
                                $errors++;

                                continue;
                            }
                        }

                        // Try to resolve the owner model class
                        $modelClass = $this->resolveModelClass($ownerType);

                        if (! $modelClass || ! class_exists($modelClass)) {
                            $this->warn("Model class not found for: {$ownerType}");
                            $errors++;

                            continue;
                        }

                        // Find the owner
                        $owner = $modelClass::find($ownerId);

                        if (! $owner) {
                            $this->warn("Owner not found: {$modelClass}#{$ownerId}");
                            $errors++;

                            continue;
                        }

                        if (! $isDryRun) {
                            // Sync to database
                            ModelCounter::addDelta($owner, $counterKey, $value, $interval, $periodStart);
                        }

                        $intervalInfo = $interval ? " [{$interval->value}:{$parts[4]}]" : '';
                        $this->line("  ✓ {$modelClass}#{$ownerId} [{$counterKey}]{$intervalInfo} += {$value}");
                        $synced++;

                    } catch (\Exception $e) {
                        $this->error("Error processing {$key}: " . $e->getMessage());
                        $errors++;
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

            return $errors > 0 ? self::FAILURE : self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Resolve the full model class name from the owner type.
     */
    protected function resolveModelClass(string $ownerType): ?string
    {
        // Try common conventions
        $attempts = [
            "App\\Models\\" . ucfirst($ownerType),
            "App\\Models\\" . $ownerType,
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
    protected function parsePeriodKey(Interval $interval, string $periodKey): ?\Carbon\Carbon
    {
        try {
            return match ($interval) {
                Interval::Day => \Carbon\Carbon::createFromFormat('Y-m-d', $periodKey)?->startOfDay(),
                Interval::Week => $this->parseIsoWeek($periodKey),
                Interval::Month => \Carbon\Carbon::createFromFormat('Y-m', $periodKey)?->startOfMonth(),
                Interval::Quarter => $this->parseQuarter($periodKey),
                Interval::Year => \Carbon\Carbon::createFromFormat('Y', $periodKey)?->startOfYear(),
            };
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse ISO week format (e.g., "2024-W01") to Carbon.
     */
    protected function parseIsoWeek(string $weekKey): ?\Carbon\Carbon
    {
        if (preg_match('/^(\d{4})-W?(\d{2})$/', $weekKey, $matches)) {
            return \Carbon\Carbon::now()
                ->setISODate((int) $matches[1], (int) $matches[2])
                ->startOfWeek();
        }

        return null;
    }

    /**
     * Parse quarter format (e.g., "2024-Q1") to Carbon.
     */
    protected function parseQuarter(string $quarterKey): ?\Carbon\Carbon
    {
        if (preg_match('/^(\d{4})-Q(\d)$/', $quarterKey, $matches)) {
            $year = (int) $matches[1];
            $quarter = (int) $matches[2];
            $month = ($quarter - 1) * 3 + 1;

            return \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        }

        return null;
    }
}
