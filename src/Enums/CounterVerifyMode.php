<?php

namespace Rejoose\ModelCounter\Enums;

/**
 * How a stored counter value is checked against the source-of-truth closure
 * declared on the counter definition.
 */
enum CounterVerifyMode: string
{
    /**
     * Stored value must equal the source. Recountable from source — recountAllCounters
     * will rebuild the stored value by calling the recount closure.
     */
    case Equal = 'equal';

    /**
     * Stored value must be at least the source. Used for cumulative event counters
     * where the source query can only see the latest state (e.g. counting updates
     * by `updated_at` undercounts records updated more than once). Not recountable —
     * recountAllCounters will skip these to avoid clobbering live event totals.
     */
    case AtLeast = 'at_least';
}
