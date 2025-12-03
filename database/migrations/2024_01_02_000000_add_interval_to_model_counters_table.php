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

        // Skip if columns already exist (for new installations that use the updated first migration)
        if (Schema::hasColumn($tableName, 'interval')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            // Add interval column (nullable for single/total counts)
            $table->string('interval', 20)->nullable()->after('key');

            // Add period_start for interval-based counting
            $table->date('period_start')->nullable()->after('interval');
        });

        // Drop the old unique constraint
        Schema::table($tableName, function (Blueprint $table) {
            $table->dropUnique('model_counters_unique');
        });

        // Add new unique constraint that includes interval and period_start
        Schema::table($tableName, function (Blueprint $table) {
            $table->unique(
                ['owner_type', 'owner_id', 'key', 'interval', 'period_start'],
                'model_counters_unique'
            );

            // Index for efficient period queries
            $table->index(['key', 'interval', 'period_start'], 'model_counters_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('counter.table_name', 'model_counters');

        // Check if the period_index exists before trying to drop
        $sm = Schema::getConnection()->getDoctrineSchemaManager();
        $indexes = $sm->listTableIndexes($tableName);

        if (isset($indexes['model_counters_period_index'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex('model_counters_period_index');
                $table->dropUnique('model_counters_unique');
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->unique(['owner_type', 'owner_id', 'key'], 'model_counters_unique');
                $table->dropColumn(['interval', 'period_start']);
            });
        }
    }
};
