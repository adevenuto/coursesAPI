<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trims the API request detail log to its retention window.
 *
 * This is not only housekeeping — retention IS the privacy control for this
 * table. It holds IP addresses, user agents and search terms, and the retention
 * period is what the privacy policy commits to. The `api_usage` rollup is
 * unaffected: that carries no personal data and is kept indefinitely for billing.
 *
 * Deletes in bounded chunks so a first run against a large table never takes a
 * long lock on shared hosting.
 */
class PruneApiRequests extends Command
{
    protected $signature = 'api:prune-requests
                            {--days= : Override the retention window}
                            {--chunk=5000 : Rows to delete per statement}
                            {--dry-run : Report what would be deleted and stop}';

    protected $description = 'Delete API request detail rows past the retention window';

    public function handle(): int
    {
        // Not ?: — '0' is falsy in PHP, so an explicit --days=0 would silently
        // fall through to the configured window instead of being refused.
        $override = $this->option('days');
        $days = $override !== null
            ? (int) $override
            : (int) config('api.analytics.retention_days', 90);

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->startOfDay();
        $stale = DB::table('api_requests')->where('created_at', '<', $cutoff);

        $this->info(sprintf('Retention %d days — pruning rows before %s.', $days, $cutoff->toDateTimeString()));

        if ($this->option('dry-run')) {
            $this->line(sprintf('  %s row(s) would be deleted.', number_format($stale->count())));

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $deleted = 0;

        do {
            $batch = DB::table('api_requests')
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf('  Deleted %s row(s). %s remain.',
            number_format($deleted),
            number_format(DB::table('api_requests')->count()),
        ));

        return self::SUCCESS;
    }
}
