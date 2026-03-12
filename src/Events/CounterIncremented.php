<?php

namespace Rejoose\ModelCounter\Events;

use Illuminate\Database\Eloquent\Model;
use Rejoose\ModelCounter\Enums\Interval;

class CounterIncremented
{
    public function __construct(
        public readonly Model $owner,
        public readonly string $key,
        public readonly int $amount,
        public readonly ?Interval $interval = null,
    ) {}
}
