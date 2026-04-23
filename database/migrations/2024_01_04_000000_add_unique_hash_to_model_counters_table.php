<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('counter.table_name', 'model_counters');

        if (Schema::hasColumn($table, 'unique_hash')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->char('unique_hash', 40)->nullable()->after('count');
        });

        // Merge any logical duplicates that slipped past the old composite
        // unique constraint (MySQL/Postgres treat NULLs as distinct, so
        // `(owner, key, NULL, NULL)` could exist multiple times). Without
        // this step the new single-column unique index below would fail to
        // create when duplicates are present.
        $this->dedupeAndBackfill($table);

        // Drop the old composite unique constraint before adding the new one.
        // dropUnique() only queues the statement inside the closure - a
        // missing index throws from Schema::table() itself, so the try/catch
        // has to wrap the outer call.
        try {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique('model_counters_unique');
            });
        } catch (Throwable) {
            // index may not exist on fresh installs
        }

        // ->change() on Laravel 11+ works without doctrine/dbal for all
        // drivers we care about (MySQL, Postgres, SQLite, SQL Server). The
        // package's composer constraints already require Laravel 11+.
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->char('unique_hash', 40)->nullable(false)->change();
            $blueprint->unique('unique_hash', 'model_counters_unique_hash');
        });
    }

    public function down(): void
    {
        $table = config('counter.table_name', 'model_counters');

        if (! Schema::hasColumn($table, 'unique_hash')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropUnique('model_counters_unique_hash');
        });

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unique(
                ['owner_type', 'owner_id', 'key', 'interval', 'period_start'],
                'model_counters_unique'
            );
            $blueprint->dropColumn('unique_hash');
        });
    }

    /**
     * Compute the hash for every existing row, merge logical duplicates by
     * summing counts, and backfill the hash column. Uses one INSERT…ON
     * DUPLICATE KEY UPDATE per chunk instead of an UPDATE per row.
     */
    protected function dedupeAndBackfill(string $table): void
    {
        $hashes = [];
        $duplicates = [];

        DB::table($table)->orderBy('id')->chunkById(1000, function ($rows) use ($table, &$hashes, &$duplicates) {
            $updates = [];

            foreach ($rows as $row) {
                $hash = sha1(implode('|', [
                    $row->owner_type,
                    (string) $row->owner_id,
                    $row->key,
                    $row->interval ?? '',
                    $row->period_start ?? '',
                ]));

                if (isset($hashes[$hash])) {
                    $duplicates[] = [
                        'keeper_id' => $hashes[$hash],
                        'dup_id' => $row->id,
                        'count' => (int) $row->count,
                    ];

                    continue;
                }

                $hashes[$hash] = $row->id;
                $updates[] = ['id' => $row->id, 'unique_hash' => $hash];
            }

            if ($updates !== []) {
                // `id` is the primary key, so ON CONFLICT / ON DUPLICATE KEY
                // UPDATE hits the existing row instead of inserting.
                DB::table($table)->upsert($updates, ['id'], ['unique_hash']);
            }
        });

        // Fold duplicate counts into the kept row, then delete the duplicates.
        foreach ($duplicates as $dup) {
            DB::table($table)
                ->where('id', $dup['keeper_id'])
                ->increment('count', $dup['count']);

            DB::table($table)
                ->where('id', $dup['dup_id'])
                ->delete();
        }
    }
};
