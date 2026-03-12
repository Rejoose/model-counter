<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = config('counter.table_name', 'model_counters');

        Schema::table($tableName, function (Blueprint $table) {
            $table->index(['key', 'count'], 'model_counters_key_count_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('counter.table_name', 'model_counters');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropIndex('model_counters_key_count_index');
        });
    }
};
