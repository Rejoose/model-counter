<?php

namespace Rejoose\ModelCounter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $store = Cache::store(config('counter.store'));
        $prefix = config('counter.prefix');
        $batchSize = config('counter.sync_batch_size', 1000);

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
                $this->info("Processing batch " . ($batchIndex + 1) . " of " . count($batches) . "...");

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

                        if (count($parts) !== 3) {
                            $this->warn("Invalid key format: {$key}");
                            $errors++;
                            continue;
                        }

                        [$ownerType, $ownerId, $counterKey] = $parts;

                        // Try to resolve the owner model class
                        $modelClass = $this->resolveModelClass($ownerType);

                        if (!$modelClass || !class_exists($modelClass)) {
                            $this->warn("Model class not found for: {$ownerType}");
                            $errors++;
                            continue;
                        }

                        // Find the owner
                        $owner = $modelClass::find($ownerId);

                        if (!$owner) {
                            $this->warn("Owner not found: {$modelClass}#{$ownerId}");
                            $errors++;
                            continue;
                        }

                        if (!$isDryRun) {
                            // Sync to database
                            ModelCounter::addDelta($owner, $counterKey, $value);
                        }

                        $this->line("  ✓ {$modelClass}#{$ownerId} [{$counterKey}] += {$value}");
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
}

