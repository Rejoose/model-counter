<?php

namespace Rejoose\ModelCounter\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Rejoose\ModelCounter\Enums\Interval;

/**
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $key
 * @property ?Interval $interval
 * @property ?string $period_start
 * @property int $count
 * @property string $unique_hash
 */
class ModelCounter extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'interval' => Interval::class,
            // period_start is stored as Y-m-d string, not cast to date
            // to avoid timezone/datetime format issues across databases
        ];
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('counter.table_name', 'model_counters');
    }

    protected static function booted(): void
    {
        static::creating(function (self $counter) {
            if ($counter->unique_hash) {
                return;
            }

            $counter->unique_hash = static::hashFor(
                $counter->owner_type,
                $counter->owner_id,
                $counter->key,
                $counter->interval instanceof Interval ? $counter->interval->value : $counter->interval,
                $counter->period_start
            );
        });
    }

    /**
     * Build the deterministic uniqueness hash for a counter row.
     *
     * Using a single-column sha1 works around MySQL/Postgres treating NULL
     * as distinct in composite unique indexes, which let duplicate
     * `(owner, key, NULL, NULL)` rows slip past the old
     * model_counters_unique constraint.
     */
    public static function hashFor(
        string $ownerType,
        int|string $ownerId,
        string $key,
        ?string $interval,
        Carbon|string|null $periodStart
    ): string {
        if ($periodStart instanceof Carbon) {
            $periodStart = $periodStart->toDateString();
        }

        return sha1(implode('|', [
            $ownerType,
            (string) $ownerId,
            $key,
            $interval ?? '',
            $periodStart ?? '',
        ]));
    }

    /**
     * Get the current counter value from the database for a given owner and key.
     */
    public static function valueFor(
        Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): int {
        $hash = static::hashFor(
            $owner->getMorphClass(),
            $owner->getKey(),
            $key,
            $interval?->value,
            $interval !== null ? ($periodStart ?? $interval->periodStart()) : null
        );

        return (int) (static::where('unique_hash', $hash)->value('count') ?? 0);
    }

    /**
     * Add a delta to an existing counter (or create it if it doesn't exist).
     */
    public static function addDelta(
        Model $owner,
        string $key,
        int $amount,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        static::addDeltaRaw(
            $owner->getMorphClass(),
            $owner->getKey(),
            $key,
            $amount,
            $interval,
            $periodStart
        );
    }

    /**
     * Add a delta without requiring a loaded owner model. Used by sync so we
     * don't pay for N `Model::find()` lookups per batch.
     */
    public static function addDeltaRaw(
        string $ownerType,
        int|string $ownerId,
        string $key,
        int $amount,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        $periodStartDate = null;
        if ($interval !== null) {
            $periodStartDate = ($periodStart ?? $interval->periodStart())->toDateString();
        }

        $hash = static::hashFor(
            $ownerType,
            $ownerId,
            $key,
            $interval?->value,
            $periodStartDate
        );

        if (static::incrementByHash($hash, $amount)) {
            return;
        }

        $inserted = static::insertOrIgnore([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'key' => $key,
            'interval' => $interval?->value,
            'period_start' => $periodStartDate,
            'count' => $amount,
            'unique_hash' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted) {
            return;
        }

        // Lost the insert race with another worker; retry the update.
        static::incrementByHash($hash, $amount);
    }

    /**
     * Apply a parameter-bound increment to the row matching $hash.
     * Returns the number of affected rows (0 if no row exists yet).
     */
    protected static function incrementByHash(string $hash, int $amount): int
    {
        return DB::table((new self)->getTable())
            ->where('unique_hash', $hash)
            ->increment('count', $amount, ['updated_at' => now()]);
    }

    /**
     * Drivers that support a single-statement upsert with an additive
     * `count = count + EXCLUDED.count` semantics. Other drivers (sqlsrv) fall
     * back to the per-row addDeltaRaw() path.
     */
    public static function supportsBulkAddDelta(?string $driver = null): bool
    {
        $driver ??= DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true);
    }

    /**
     * Apply many counter deltas in a single batched UPSERT. Replaces N
     * round-trip update/insert pairs with one statement per chunk.
     *
     * Each row must contain owner_type, owner_id, key, amount, and
     * (optionally) interval (string|null) + period_start (Y-m-d|null).
     * Rows with the same logical hash are summed before the write so the
     * VALUES list never collides with itself (Postgres/SQLite reject
     * "ON CONFLICT" affecting the same row twice).
     *
     * @param  array<int, array{owner_type: string, owner_id: int|string, key: string, amount: int, interval?: ?string, period_start?: ?string}>  $rows
     */
    public static function bulkAddDelta(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (! static::supportsBulkAddDelta($driver)) {
            // Caller is expected to have checked supportsBulkAddDelta(); guard
            // anyway so a misconfigured driver doesn't silently lose deltas.
            // The row payload uses the wire format (interval as string|null,
            // period_start as Y-m-d|null), so reconstruct the rich types
            // before delegating - otherwise interval-based deltas would be
            // committed as non-interval rows and corrupt the counter.
            foreach ($rows as $row) {
                $intervalString = $row['interval'] ?? null;
                $periodStartString = $row['period_start'] ?? null;

                static::addDeltaRaw(
                    $row['owner_type'],
                    $row['owner_id'],
                    $row['key'],
                    $row['amount'],
                    $intervalString !== null ? Interval::from($intervalString) : null,
                    $periodStartString !== null ? Carbon::parse($periodStartString) : null,
                );
            }

            return;
        }

        $byHash = [];
        foreach ($rows as $row) {
            $interval = $row['interval'] ?? null;
            $periodStart = $row['period_start'] ?? null;
            $amount = (int) $row['amount'];

            $hash = static::hashFor(
                $row['owner_type'],
                $row['owner_id'],
                $row['key'],
                $interval,
                $periodStart
            );

            if (isset($byHash[$hash])) {
                $byHash[$hash]['count'] += $amount;

                continue;
            }

            $byHash[$hash] = [
                'owner_type' => $row['owner_type'],
                'owner_id' => $row['owner_id'],
                'key' => $row['key'],
                'interval' => $interval,
                'period_start' => $periodStart,
                'count' => $amount,
                'unique_hash' => $hash,
            ];
        }

        $byHash = array_filter($byHash, fn (array $r): bool => $r['count'] !== 0);

        if ($byHash === []) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        $table = (new self)->getTable();
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);

        $columns = ['owner_type', 'owner_id', 'key', 'interval', 'period_start', 'count', 'unique_hash', 'created_at', 'updated_at'];
        $wrappedColumns = implode(', ', array_map(fn (string $c): string => $grammar->wrap($c), $columns));
        $countCol = $grammar->wrap('count');
        $updatedAtCol = $grammar->wrap('updated_at');

        // Wrap the chunked write in a transaction so a partial failure on
        // chunk N doesn't leave chunks 1..N-1 committed. Without this, a
        // caller falling back to per-row addDeltaRaw() after a chunk error
        // would double-count the already-committed rows.
        $connection->transaction(function () use ($connection, $driver, $byHash, $columns, $wrappedTable, $wrappedColumns, $countCol, $updatedAtCol, $now): void {
            // Chunk so we never blow the driver's bound-parameter ceiling
            // (SQLite older builds cap at 999; Postgres at 65535). 200 rows
            // x 9 cols = 1800 placeholders is comfortably under all of them.
            foreach (array_chunk(array_values($byHash), 200) as $chunk) {
                $placeholders = '(' . rtrim(str_repeat('?, ', count($columns)), ', ') . ')';
                $valuesClause = implode(', ', array_fill(0, count($chunk), $placeholders));

                $bindings = [];
                foreach ($chunk as $r) {
                    $bindings[] = $r['owner_type'];
                    $bindings[] = $r['owner_id'];
                    $bindings[] = $r['key'];
                    $bindings[] = $r['interval'];
                    $bindings[] = $r['period_start'];
                    $bindings[] = $r['count'];
                    $bindings[] = $r['unique_hash'];
                    $bindings[] = $now;
                    $bindings[] = $now;
                }

                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    // VALUES() is deprecated in MySQL 8.0.20+ in favour of
                    // an alias, but is still accepted and works on every
                    // supported MySQL/MariaDB version. The alias form would
                    // break MySQL <8.
                    $sql = "INSERT INTO {$wrappedTable} ({$wrappedColumns}) VALUES {$valuesClause} "
                        ."ON DUPLICATE KEY UPDATE {$countCol} = {$countCol} + VALUES({$countCol}), "
                        ."{$updatedAtCol} = VALUES({$updatedAtCol})";
                } else { // pgsql / sqlite
                    $sql = "INSERT INTO {$wrappedTable} ({$wrappedColumns}) VALUES {$valuesClause} "
                        ."ON CONFLICT (unique_hash) DO UPDATE SET "
                        ."{$countCol} = {$wrappedTable}.{$countCol} + EXCLUDED.{$countCol}, "
                        ."{$updatedAtCol} = EXCLUDED.{$updatedAtCol}";
                }

                $connection->statement($sql, $bindings);
            }
        });
    }

    /**
     * Reset a counter to zero.
     */
    public static function resetValue(
        Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        static::setValue($owner, $key, 0, $interval, $periodStart);
    }

    /**
     * Set a counter to a specific value.
     */
    public static function setValue(
        Model $owner,
        string $key,
        int $value,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        $periodStartDate = null;
        if ($interval !== null) {
            $periodStartDate = ($periodStart ?? $interval->periodStart())->toDateString();
        }

        $hash = static::hashFor(
            $owner->getMorphClass(),
            $owner->getKey(),
            $key,
            $interval?->value,
            $periodStartDate
        );

        $record = static::where('unique_hash', $hash)->first();

        if ($record) {
            $record->update(['count' => $value]);

            return;
        }

        // Populate unique_hash explicitly here so the insert succeeds even
        // when the `creating` event listener is suppressed by Event::fake()
        // in tests.
        static::create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'interval' => $interval?->value,
            'period_start' => $periodStartDate,
            'count' => $value,
            'unique_hash' => $hash,
        ]);
    }

    /**
     * Get all counters for a given owner.
     *
     * @return array<string, int>
     */
    public static function allForOwner(Model $owner): array
    {
        return static::where([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ])
            ->whereNull('interval')
            ->whereNull('period_start')
            ->pluck('count', 'key')
            ->toArray();
    }

    /**
     * Get counter history for multiple periods.
     *
     * @return array<string, int> Period key => count
     */
    public static function history(
        Model $owner,
        string $key,
        Interval $interval,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        $periodStarts = $interval->previousPeriods($periods, $fromDate);
        $dateStrings = array_map(fn ($p) => $p->format('Y-m-d'), $periodStarts);

        $results = static::where([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'interval' => $interval->value,
        ])
            ->whereIn('period_start', $dateStrings)
            ->pluck('count', 'period_start')
            ->toArray();

        $history = [];
        foreach ($periodStarts as $periodStart) {
            $periodKey = $interval->periodKey($periodStart);
            $dateKey = $periodStart->format('Y-m-d');
            $history[$periodKey] = $results[$dateKey] ?? 0;
        }

        return $history;
    }

    /**
     * Get sum of counts across all (or a date-bounded range of) periods for an interval-based counter.
     */
    public static function sumForInterval(
        Model $owner,
        string $key,
        Interval $interval,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): int {
        $query = static::where([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'interval' => $interval->value,
        ]);

        if ($from !== null) {
            $query->where('period_start', '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->where('period_start', '<=', $to->toDateString());
        }

        return (int) $query->sum('count');
    }

    /**
     * Delete all counter records for an owner and key.
     */
    public static function deleteFor(
        Model $owner,
        string $key,
        ?Interval $interval = null
    ): int {
        $query = static::where([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'key' => $key,
        ]);

        if ($interval !== null) {
            $query->where('interval', $interval->value);
        }

        return $query->delete();
    }

    /**
     * Define the polymorphic relationship to the owner.
     */
    public function owner()
    {
        return $this->morphTo();
    }
}
