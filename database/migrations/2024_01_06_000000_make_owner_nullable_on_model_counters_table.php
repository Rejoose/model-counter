<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow global (ownerless) counters. owner_type / owner_id become nullable so
 * an app-wide counter (no owning model) can be stored with NULL owner. The
 * single-column unique_hash index continues to guarantee uniqueness — see
 * ModelCounter::hashFor(), which hashes a null owner to empty strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('counter.table_name', 'model_counters');

        Schema::table($table, function (Blueprint $table) {
            $table->string('owner_type', 255)->nullable()->change();
            $table->unsignedBigInteger('owner_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $table = config('counter.table_name', 'model_counters');

        // Global rows have no owner and cannot satisfy a NOT NULL constraint;
        // drop them before reverting the column nullability.
        DB::table($table)->whereNull('owner_type')->orWhereNull('owner_id')->delete();

        Schema::table($table, function (Blueprint $table) {
            $table->string('owner_type', 255)->nullable(false)->change();
            $table->unsignedBigInteger('owner_id')->nullable(false)->change();
        });
    }
};
