<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('counter.table_name', 'model_counters');

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->index(
                ['owner_type', 'owner_id', 'key', 'interval', 'period_start'],
                'mc_owner_key_interval_period_idx'
            );
        });
    }

    public function down(): void
    {
        $table = config('counter.table_name', 'model_counters');

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropIndex('mc_owner_key_interval_period_idx');
        });
    }
};
