<?php

namespace App\Console\Commands;

use App\Models\ScorecardScan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Checks everything scorecard scanning needs from the machine it runs on.
 *
 * Exists because the prerequisites differ per environment and each one fails
 * differently in the browser: no gd rejects the upload, no key fails the parse,
 * and a short request timeout kills the parse mid-call — which costs money and
 * leaves nothing to show for it. Cheaper to find over SSH before the first real
 * upload than to diagnose from a stuck scan.
 */
class ScorecardDoctor extends Command
{
    protected $signature = 'scorecard:doctor';

    protected $description = 'Check that this environment can run scorecard scanning';

    public function handle(): int
    {
        $failures = 0;

        $this->line('');
        $failures += $this->check(
            'gd extension',
            extension_loaded('gd'),
            'installed',
            'MISSING — uploads will be rejected. hPanel → Advanced → PHP Configuration.',
        );

        $this->check(
            'exif extension',
            extension_loaded('exif'),
            'installed',
            'absent — rotated phone photos won\'t be straightened (not fatal)',
            fatal: false,
        );

        $key = (string) config('services.anthropic.key');
        $failures += $this->check(
            'ANTHROPIC_API_KEY',
            $key !== '',
            'set ('.substr($key, 0, 8).'…, '.strlen($key).' chars)',
            'MISSING — every parse will fail. Add it to .env, then run `php artisan config:cache`.',
        );

        $this->line(sprintf('  %-22s %s', 'model', config('services.anthropic.model')));

        // The parse runs inline, so a short ceiling truncates it mid-call.
        $limit = (int) ini_get('max_execution_time');
        $canRaise = function_exists('set_time_limit') && ! in_array(
            'set_time_limit', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true,
        );
        $this->check(
            'max_execution_time',
            $limit === 0 || $limit >= 180 || $canRaise,
            $limit === 0 ? 'unlimited' : $limit.'s'.($canRaise ? ' (raisable to 300s)' : ''),
            $limit.'s and set_time_limit is disabled — a slow card may be cut off mid-parse',
            fatal: false,
        );

        $disk = Storage::disk(ScorecardScan::DISK);
        $writable = false;
        try {
            $probe = 'scorecards/.doctor-'.bin2hex(random_bytes(4));
            $disk->put($probe, 'ok');
            $writable = $disk->get($probe) === 'ok';
            $disk->delete($probe);
        } catch (\Throwable) {
            // stays false
        }
        $failures += $this->check(
            'scorecard storage',
            $writable,
            'writable ('.ScorecardScan::DISK.')',
            'NOT WRITABLE — run `chmod -R ug+rw storage`',
        );

        $tableExists = $this->tableExists();
        $failures += $this->check(
            'scorecard_scans table',
            $tableExists,
            $tableExists ? ScorecardScan::count().' scan(s) recorded' : '',
            'MISSING — run `php artisan migrate --force`',
        );

        $this->line('');

        if ($failures > 0) {
            $this->error("{$failures} blocking problem(s). Scanning will not work until they're fixed.");

            return self::FAILURE;
        }

        $this->info('Ready. Upload a card at /scorecard-scans/create');
        $this->line('');

        return self::SUCCESS;
    }

    private function tableExists(): bool
    {
        try {
            ScorecardScan::count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function check(string $label, bool $ok, string $good, string $bad, bool $fatal = true): int
    {
        $mark = $ok ? '<info>✓</info>' : ($fatal ? '<error>✗</error>' : '<comment>!</comment>');
        $this->line(sprintf('  %s %-20s %s', $mark, $label, $ok ? $good : $bad));

        return $ok || ! $fatal ? 0 : 1;
    }
}
