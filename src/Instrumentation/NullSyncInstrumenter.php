<?php

namespace Rejoose\ModelCounter\Instrumentation;

use Closure;
use Rejoose\ModelCounter\Contracts\SyncInstrumenter;

/**
 * Default instrumenter: runs each phase and records nothing.
 */
class NullSyncInstrumenter implements SyncInstrumenter
{
    public function measure(string $op, array $data, Closure $callback): mixed
    {
        return $callback();
    }
}
