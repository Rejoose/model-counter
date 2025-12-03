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
        Schema::create(config('counter.table_name', 'model_counters'), function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('key', 100);
            $table->string('interval', 20)->nullable();
            $table->date('period_start')->nullable();
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();

            // Unique constraint to prevent duplicate counters
            $table->unique(
                ['owner_type', 'owner_id', 'key', 'interval', 'period_start'],
                'model_counters_unique'
            );

            // Index for efficient querying by owner
            $table->index(['owner_type', 'owner_id'], 'model_counters_owner_index');

            // Index for efficient period queries
            $table->index(['key', 'interval', 'period_start'], 'model_counters_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('counter.table_name', 'model_counters'));
    }
};
