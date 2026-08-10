<?php

namespace App\Console\Commands;

use App\Jobs\ParseScorecardScan;
use App\Models\ScorecardScan;
use Illuminate\Console\Command;

/**
 * Re-run the parse for a staged scan from the CLI.
 *
 * Useful for iterating on the prompt or schema against a real card without
 * going through the browser, and for re-parsing a scan that failed on a
 * transient error. Applying is deliberately not exposed here — that stays an
 * explicit editor action against the diff preview.
 */
class ParseScorecard extends Command
{
    protected $signature = 'scorecard:parse
                            {scan : the scorecard_scans id}
                            {--force : re-parse even if the scan already has a result}';

    protected $description = 'Parse a staged scorecard scan and store the result';

    public function handle(): int
    {
        $scan = ScorecardScan::find((int) $this->argument('scan'));

        if ($scan === null) {
            $this->error('No scan with that id.');

            return self::FAILURE;
        }

        if ($scan->raw_parse !== null && ! $this->option('force')) {
            $this->warn("Scan {$scan->id} is already parsed. Pass --force to parse it again.");

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            // Otherwise the job would just copy this scan's own earlier result
            // back over itself via the content-hash reuse path.
            $scan->update(['raw_parse' => null, 'usage' => null, 'verification' => null]);
        }

        $this->info("Parsing scan {$scan->id} (".count($scan->images).' image(s))…');

        ParseScorecardScan::dispatchSync($scan->id);

        $scan->refresh();

        if ($scan->status === ScorecardScan::STATUS_FAILED) {
            $this->error($scan->error ?? 'Parse failed.');

            return self::FAILURE;
        }

        $parsed = $scan->parsed() ?? [];

        $this->info('Parsed: '.($parsed['name'] ?? 'unnamed card'));
        $this->line('  tees:  '.count($parsed['tees'] ?? []));
        $this->line('  holes: '.count($parsed['holes'] ?? []));
        $this->line('  units: '.($parsed['units'] ?? 'yards'));

        if (! empty($parsed['parseNotes'])) {
            $this->newLine();
            $this->comment('Notes: '.$parsed['parseNotes']);
        }

        if (($scan->usage['reused_from_scan'] ?? null) !== null) {
            $this->newLine();
            $this->comment('Reused the parse from scan '.$scan->usage['reused_from_scan'].' — no API call made.');
        }

        return self::SUCCESS;
    }
}
