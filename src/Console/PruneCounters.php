<?php

namespace Rejoose\ModelCounter\Console;

use Illuminate\Console\Command;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Models\ModelCounter;

class PruneCounters extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'counter:prune
                          {--older-than= : Delete records older than this many days (default: uses retention config)}
                          {--interval= : Only prune a specific interval type (day, week, month, quarter, year)}
                          {--dry-run : Display what would be pruned without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Prune old interval-based counter records from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $intervalFilter = $this->option('interval');
        $olderThanOption = $this->option('older-than');

        if ($olderThanOption !== null && ((string) $olderThanOption === '' || (int) $olderThanOption <= 0)) {
            $this->error('The --older-than option must be a positive integer.');

            return self::FAILURE;
        }

        $olderThanDays = $olderThanOption !== null ? (int) $olderThanOption : null;

        $intervals = $intervalFilter
            ? [Interval::tryFrom($intervalFilter)]
            : Interval::cases();

        $intervals = array_filter($intervals);

        if (empty($intervals)) {
            $this->error("Invalid interval: {$intervalFilter}");

            return self::FAILURE;
        }

        $totalDeleted = 0;

        foreach ($intervals as $interval) {
            $retentionDays = $olderThanDays
                ?? config("counter.retention.{$interval->value}")
                ?? null;

            if ($retentionDays === null) {
                $this->line("Skipping {$interval->value}: no retention period configured.");

                continue;
            }

            $cutoffDate = now()->subDays((int) $retentionDays)->toDateString();

            $query = ModelCounter::where('interval', $interval->value)
                ->where('period_start', '<', $cutoffDate);

            $count = $query->count();

            if ($count === 0) {
                $this->line("No {$interval->value} records older than {$retentionDays} days.");

                continue;
            }

            if ($isDryRun) {
                $this->info("Would delete {$count} {$interval->value} record(s) older than {$retentionDays} days.");
            } else {
                $deleted = $query->delete();
                $this->info("Deleted {$deleted} {$interval->value} record(s) older than {$retentionDays} days.");
                $totalDeleted += $deleted;
            }
        }

        $this->newLine();
        if ($isDryRun) {
            $this->warn('This was a dry run. No data was actually deleted.');
        } else {
            $this->info("Pruning complete. Total deleted: {$totalDeleted}");
        }

        return self::SUCCESS;
    }
}
