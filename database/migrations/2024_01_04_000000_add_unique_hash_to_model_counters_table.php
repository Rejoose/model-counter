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

        DB::table($table)->orderBy('id')->chunkById(1000, function ($rows) use ($table) {
            foreach ($rows as $row) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update([
                        'unique_hash' => sha1(implode('|', [
                            $row->owner_type,
                            (string) $row->owner_id,
                            $row->key,
                            $row->interval ?? '',
                            $row->period_start ?? '',
                        ])),
                    ]);
            }
        });

        Schema::table($table, function (Blueprint $blueprint) {
            try {
                $blueprint->dropUnique('model_counters_unique');
            } catch (Throwable) {
                // index may not exist on fresh installs
            }
        });

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
};
