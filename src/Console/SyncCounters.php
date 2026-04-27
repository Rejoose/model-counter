<?php

namespace Rejoose\ModelCounter\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Events\CounterSynced;
use Rejoose\ModelCounter\Models\ModelCounter;

class SyncCounters extends Command
{
    protected $signature = 'counter:sync
                          {--dry-run : Display what would be synced without actually syncing}
                          {--pattern= : Only sync keys matching this pattern}
                          {--lock-ttl=300 : Seconds to hold the overlap lock}';

    protected $description = 'Sync cached counter increments from Redis to the database';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $pattern = $this->option('pattern');

        $storeName = (string) config('counter.store');
        $repository = Cache::store($storeName);
        $store = $repository->getStore();
        $counterPrefix = (string) config('counter.prefix');
        $batchSize = (int) config('counter.sync_batch_size', 1000);

        if ($storeName === 'array') {
            $this->info('Using array cache store - sync is not needed.');
            $this->info('Array cache stores data directly in the database on each operation.');

            return self::SUCCESS;
        }

        // We require a Redis-backed store. Other drivers don't expose the
        // SCAN/GET/DECRBY primitives this command relies on. (getPrefix is
        // part of the Store contract since Laravel 13, so we only probe for
        // the Redis-specific connection() accessor.)
        if (! method_exists($store, 'connection')) {
            $this->error("Cache store '{$storeName}' is not supported by counter:sync.");
            $this->error('Use the Redis store for production, or set COUNTER_DIRECT=true.');

            return self::FAILURE;
        }

        if (! $store instanceof LockProvider) {
            $this->error("Cache store '{$storeName}' does not support locks.");

            return self::FAILURE;
        }

        // Acquire the lock through the same store we're about to scan. Using
        // the Cache facade would land on `cache.default`, which can differ
        // from `counter.store` - two sync processes could then run
        // concurrently against the same counter keyspace.
        $lock = $store->lock('counter-sync-lock', (int) $this->option('lock-ttl'));

        try {
            if (! $lock->get()) {
                $this->warn('Another counter:sync run is in progress; skipping.');

                return self::SUCCESS;
            }

            return $this->runSync($store, $counterPrefix, $pattern, $batchSize, $isDryRun);
        } catch (LockTimeoutException $e) {
            $this->warn('Could not acquire sync lock: '.$e->getMessage());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }

    protected function runSync(
        $store,
        string $counterPrefix,
        ?string $pattern,
        int $batchSize,
        bool $isDryRun
    ): int {
        $redis = $store->connection();

        // The Redis cache store prepends its own prefix to every key we pass
        // to Cache::increment(). The underlying Redis client (phpredis/Predis)
        // *also* applies its connection-level prefix (e.g.
        // `database.redis.options.prefix`). Earlier versions of this command
        // scanned only the counter prefix and silently matched nothing.
        $cachePrefix = (string) $store->getPrefix();
        $connectionPrefix = $this->connectionPrefix($redis);
        $logicalPrefix = $cachePrefix.$counterPrefix;
        $wirePrefix = $connectionPrefix.$logicalPrefix;

        $searchPattern = $wirePrefix.($pattern ? $pattern.'*' : '*');

        // phpredis (>=6.x) treats an initial cursor of 0 or "0" as "already
        // finished" and returns false without scanning. Passing null is the
        // only portable way to start an iteration.
        $cursor = null;
        $totalFound = 0;
        $synced = 0;
        $skipped = 0;
        $errors = 0;

        // Process each SCAN batch as it comes back instead of accumulating
        // all matching keys in memory first - at scale the keyspace can be
        // in the millions and buffering all of it would blow up the sync
        // worker.
        do {
            $result = $redis->scan($cursor, [
                'match' => $searchPattern,
                'count' => $batchSize,
            ]);

            if ($result === false) {
                break;
            }

            [$cursor, $foundKeys] = $result;

            if (empty($foundKeys)) {
                continue;
            }

            if ($totalFound === 0) {
                $this->info('Streaming matched keys from Redis...');
            }
            $totalFound += count($foundKeys);

            $this->processBatch(
                $redis,
                $foundKeys,
                $connectionPrefix,
                $logicalPrefix,
                $isDryRun,
                $synced,
                $skipped,
                $errors
            );
        } while ((string) $cursor !== '0');

        if ($totalFound === 0) {
            $this->info('No counters found to sync.');

            return self::SUCCESS;
        }

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
    }

    /**
     * @param  array<int, string>  $foundKeys
     */
    protected function processBatch(
        object $redis,
        array $foundKeys,
        string $connectionPrefix,
        string $logicalPrefix,
        bool $isDryRun,
        int &$synced,
        int &$skipped,
        int &$errors
    ): void {
        foreach ($foundKeys as $wireKey) {
            try {
                // SCAN returns keys with the connection-level prefix
                // included (phpredis default). Strip it so subsequent
                // GET/DECRBY calls - which go back through the same
                // connection that re-adds the prefix - don't double up.
                $rawKey = $connectionPrefix !== '' && str_starts_with($wireKey, $connectionPrefix)
                    ? substr($wireKey, strlen($connectionPrefix))
                    : $wireKey;

                $parsed = $this->parseRedisKey($rawKey, $logicalPrefix);

                if ($parsed === null) {
                    $this->warn("Invalid key format: {$rawKey}");
                    $errors++;

                    continue;
                }

                // Counter::redisKey() encodes the owner type two different
                // ways: morph-map aliases are preserved verbatim, plain
                // FQCNs are lower-cased with backslashes replaced by dots.
                // DB rows store `$owner->getMorphClass()` (alias or real
                // FQCN), so we have to reverse the non-alias case to keep
                // insert rows aligned with future valueFor() lookups.
                $modelClass = $this->resolveModelClass($parsed['owner_type']);

                if ($modelClass === null) {
                    $this->warn("Model class not found for: {$parsed['owner_type']}");
                    $errors++;

                    continue;
                }

                $dbOwnerType = Relation::getMorphedModel($parsed['owner_type']) !== null
                    ? $parsed['owner_type']
                    : $modelClass;

                // GET before DECRBY (not GETDEL): if the DB write fails
                // the delta stays in Redis and the next sync retries. The
                // only remaining lossy window is a crash between the DB
                // commit and the DECRBY call.
                $value = (int) $redis->get($rawKey);

                if ($value === 0) {
                    $skipped++;

                    continue;
                }

                if (! $isDryRun) {
                    ModelCounter::addDeltaRaw(
                        $dbOwnerType,
                        $parsed['owner_id'],
                        $parsed['counter_key'],
                        $value,
                        $parsed['interval'],
                        $parsed['period_start']
                    );

                    $redis->decrby($rawKey, $value);
                }

                $intervalInfo = $parsed['interval']
                    ? " [{$parsed['interval']->value}:{$parsed['period_key_str']}]"
                    : '';
                $this->line("  ✓ {$dbOwnerType}#{$parsed['owner_id']} [{$parsed['counter_key']}]{$intervalInfo} += {$value}");
                $synced++;
            } catch (\Throwable $e) {
                $this->error("Error processing {$wireKey}: ".$e->getMessage());
                $errors++;
            }
        }
    }

    /**
     * Extract the Redis client's connection-level prefix, if any. Supports
     * both phpredis (OPT_PREFIX) and Predis (options->prefix).
     */
    protected function connectionPrefix(object $connection): string
    {
        if (! method_exists($connection, 'client')) {
            return '';
        }

        $client = $connection->client();

        if ($client instanceof \Redis && defined('\Redis::OPT_PREFIX')) {
            return (string) $client->getOption(\Redis::OPT_PREFIX);
        }

        if (method_exists($client, 'getOptions')) {
            $options = $client->getOptions();
            if (is_object($options) && isset($options->prefix)) {
                $prefix = $options->prefix;
                if (is_object($prefix) && method_exists($prefix, 'getPrefix')) {
                    return (string) $prefix->getPrefix();
                }
                if (is_string($prefix)) {
                    return $prefix;
                }
            }
        }

        return '';
    }

    /**
     * Parse a Redis key into its components.
     *
     * @return array{owner_type: string, owner_id: string, counter_key: string, interval: ?Interval, period_start: ?Carbon, period_key_str: ?string}|null
     */
    protected function parseRedisKey(string $key, string $prefix): ?array
    {
        if (! str_starts_with($key, $prefix)) {
            return null;
        }

        $parsedKey = substr($key, strlen($prefix));
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
     *
     * Returns the canonical (case-correct) class name - PHP's class_exists()
     * is case-insensitive, so we use ReflectionClass to recover the declared
     * casing. That keeps downstream hash lookups aligned with the casing
     * `Model::getMorphClass()` returns elsewhere in the package.
     */
    protected function resolveModelClass(string $ownerType): ?string
    {
        $morphedModel = Relation::getMorphedModel($ownerType);
        if ($morphedModel && class_exists($morphedModel)) {
            return $morphedModel;
        }

        // Convert dot-notation morph class back to namespace (e.g., app.models.user → App\Models\User).
        $fromDotNotation = str_replace('.', '\\', $ownerType);
        $fromDotNotation = implode('\\', array_map('ucfirst', explode('\\', $fromDotNotation)));

        $attempts = [
            $fromDotNotation,
            'App\\Models\\'.ucfirst($ownerType),
            'App\\Models\\'.$ownerType,
            $ownerType,
        ];

        foreach ($attempts as $attempt) {
            if (class_exists($attempt)) {
                try {
                    return (new \ReflectionClass($attempt))->getName();
                } catch (\ReflectionException) {
                    return $attempt;
                }
            }
        }

        return null;
    }

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
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseIsoWeek(string $weekKey): ?Carbon
    {
        if (preg_match('/^(\d{4})-W?(\d{2})$/', $weekKey, $matches)) {
            return Carbon::now()
                ->setISODate((int) $matches[1], (int) $matches[2])
                ->startOfWeek();
        }

        return null;
    }

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
