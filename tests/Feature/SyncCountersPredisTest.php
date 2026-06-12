<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Predis\Client as PredisClient;

/**
 * Re-runs the whole sync suite against the Predis client.
 *
 * Regression: counter:sync started its SCAN loop from a null cursor (a
 * phpredis >=6 requirement), but Predis serializes null to an empty string
 * on the wire and Redis rejects it with "ERR invalid cursor" — so the
 * command exited 1 on every run under Predis.
 */
class SyncCountersPredisTest extends SyncCountersTest
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.redis.client', 'predis');
    }

    protected function redisUnavailableReason(): ?string
    {
        if (! class_exists(PredisClient::class)) {
            return 'predis/predis not installed.';
        }

        try {
            Redis::connection('default')->ping();
        } catch (\Throwable $e) {
            return 'Redis not reachable: '.$e->getMessage();
        }

        return null;
    }
}
