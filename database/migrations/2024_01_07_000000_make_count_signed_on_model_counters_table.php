<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `count` was created as UNSIGNED, but decrements can legitimately drive a
 * counter negative — e.g. a Day bucket that only saw deletions of items
 * created on earlier days. On MySQL an unsigned column makes counter:sync
 * fail on every net-negative delta (1264 on insert, 1690 on the
 * `count = count + (negative)` upsert arithmetic), and the failed keys are
 * never drained from Redis, so every subsequent sync run fails too.
 *
 * The table holds one row per (owner, key, interval, period) — counters, not
 * events — so this MODIFY is small and safe to run inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('counter.table_name', 'model_counters'), function (Blueprint $table) {
            $table->bigInteger('count')->default(0)->change();
        });
    }

    public function down(): void
    {
        // Fails if negative counts exist — zero them first if you must revert.
        Schema::table(config('counter.table_name', 'model_counters'), function (Blueprint $table) {
            $table->unsignedBigInteger('count')->default(0)->change();
        });
    }
};
