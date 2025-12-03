<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration is for existing installations upgrading to interval support.
     * New installations will have these columns from the initial migration.
     */
    public function up(): void
    {
        $tableName = config('counter.table_name', 'model_counters');

        // Skip if columns already exist (for new installations)
        if (Schema::hasColumn($tableName, 'interval')) {
            return;
        }

        // Add new columns
        Schema::table($tableName, function (Blueprint $table) {
            $table->string('interval', 20)->nullable()->after('key');
            $table->date('period_start')->nullable()->after('interval');
        });

        // Drop old unique constraint and add new one with interval columns
        Schema::table($tableName, function (Blueprint $table) {
            $table->dropUnique('model_counters_unique');
        });

        Schema::table($tableName, function (Blueprint $table) {
            $table->unique(
                ['owner_type', 'owner_id', 'key', 'interval', 'period_start'],
                'model_counters_unique'
            );
            $table->index(['key', 'interval', 'period_start'], 'model_counters_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('counter.table_name', 'model_counters');

        // Only run if columns exist
        if (! Schema::hasColumn($tableName, 'interval')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropIndex('model_counters_period_index');
            $table->dropUnique('model_counters_unique');
        });

        Schema::table($tableName, function (Blueprint $table) {
            $table->unique(['owner_type', 'owner_id', 'key'], 'model_counters_unique');
            $table->dropColumn(['interval', 'period_start']);
        });
    }
};

