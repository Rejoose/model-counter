<?php

namespace Rejoose\ModelCounter\Events;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Rejoose\ModelCounter\Enums\Interval;

class CounterReset
{
    public function __construct(
        public readonly Model $owner,
        public readonly string $key,
        public readonly ?Interval $interval = null,
        public readonly ?Carbon $periodStart = null,
    ) {}
}
