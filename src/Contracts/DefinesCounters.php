<?php

namespace Rejoose\ModelCounter\Contracts;

use Rejoose\ModelCounter\CounterDefinition;

/**
 * Marks a model that declares its counters up-front, so the package can
 * recount and verify them without per-call-site closures.
 */
interface DefinesCounters
{
    /**
     * @return array<string, CounterDefinition>
     */
    public function counterDefinitions(): array;
}
