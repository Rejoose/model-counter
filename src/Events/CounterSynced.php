<?php

namespace Rejoose\ModelCounter\Events;

class CounterSynced
{
    public function __construct(
        public readonly int $synced,
        public readonly int $skipped,
        public readonly int $errors,
    ) {}
}
