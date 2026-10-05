<?php

namespace Rejoose\ModelCounter\Contracts;

use Closure;

/**
 * Wraps each phase of `counter:sync` so an app can time it (e.g. as Sentry
 * spans) without the package depending on a tracing library.
 *
 * Ops: `counter.sync.lock`, `counter.sync.scan`, `counter.sync.batch`,
 * `counter.sync.get`, `counter.sync.upsert` and `counter.sync.reclaim`.
 * `counter.sync.batch` and its phases run inside `counter.sync.scan`.
 *
 * Implementations must call `$callback` exactly once, return its result
 * unchanged and let its exceptions propagate. Bind a stateless
 * implementation: under Octane the container may share it across requests.
 */
interface SyncInstrumenter
{
    /**
     * @param  string  $op  Phase name, e.g. `counter.sync.batch`.
     * @param  array<string, mixed>  $data  Context such as `keys` (keys in
     *                                      this phase) and `dbsize`.
     */
    public function measure(string $op, array $data, Closure $callback): mixed;
}
