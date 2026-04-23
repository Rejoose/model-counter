<?php

namespace Rejoose\ModelCounter\Tests\Unit;

use Carbon\Carbon;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;

class ModelCounterHashTest extends TestCase
{
    public function test_hash_is_stable_for_same_inputs(): void
    {
        $a = ModelCounter::hashFor('App\\User', 1, 'views', null, null);
        $b = ModelCounter::hashFor('App\\User', 1, 'views', null, null);

        $this->assertSame($a, $b);
    }

    public function test_hash_differs_when_owner_differs(): void
    {
        $a = ModelCounter::hashFor('App\\User', 1, 'views', null, null);
        $b = ModelCounter::hashFor('App\\User', 2, 'views', null, null);

        $this->assertNotSame($a, $b);
    }

    public function test_hash_differs_when_interval_differs(): void
    {
        $a = ModelCounter::hashFor('App\\User', 1, 'views', 'day', '2024-06-01');
        $b = ModelCounter::hashFor('App\\User', 1, 'views', 'month', '2024-06-01');

        $this->assertNotSame($a, $b);
    }

    public function test_hash_accepts_carbon_period_start(): void
    {
        $a = ModelCounter::hashFor('App\\User', 1, 'views', 'day', Carbon::parse('2024-06-15'));
        $b = ModelCounter::hashFor('App\\User', 1, 'views', 'day', '2024-06-15');

        $this->assertSame($a, $b);
    }

    public function test_hash_treats_null_same_as_empty_string(): void
    {
        // NULL interval means "total counter"; internally encoded as '' so
        // the unique index behaves identically on MySQL/PG/SQLite.
        $nullInterval = ModelCounter::hashFor('App\\User', 1, 'views', null, null);
        $emptyInterval = ModelCounter::hashFor('App\\User', 1, 'views', '', '');

        $this->assertSame($nullInterval, $emptyInterval);
    }
}
