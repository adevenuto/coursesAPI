<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Support\TeeColor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills in teebox colours from their printed names.
 *
 * Around 90,000 teeboxes carry no colour at all — the imported data never had
 * one, and until now only a hand edit could add it. The names are overwhelmingly
 * colour words, so the same resolver a scorecard scan uses can fill most of them
 * without a human deciding anything.
 *
 * Writes straight to layout_data rather than through App\Support\CourseWriter,
 * and so records no CourseRevision. That is deliberate: a mechanical pass over
 * 22,000 courses would otherwise bury the hand edits that make the audit log
 * worth reading.
 */
class FixTeeColors extends Command
{
    /**
     * @var string
     */
    protected $signature = 'courses:fix-tee-colors
        {--apply : Write the changes (default is a dry run)}
        {--overwrite : Also replace colours that are already set}
        {--limit=0 : Stop after this many courses (0 = no limit)}
        {--chunk=500 : Courses per batch}
        {--no-index : Skip Scout syncing during the run (reindex separately afterwards)}
        {--csv= : Write every proposed change to this path}';

    /**
     * @var string
     */
    protected $description = 'Fill teebox colours from their printed names, using the scorecard scan\'s own resolver.';

    /** @var array<string, int> */
    private array $stats = [
        'courses_scanned' => 0,
        'courses_changed' => 0,
        'teeboxes_scanned' => 0,
        'colour_filled' => 0,
        'second_colour_filled' => 0,
        'overwritten' => 0,
        'already_set' => 0,
        'unnamed' => 0,
        'unresolved' => 0,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $changes = [];

    /** Names the vocabulary didn't recognise, so it can be extended. @var array<string, int> */
    private array $unrecognised = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        DB::disableQueryLog();

        $run = function () use ($apply, $limit) {
            Course::query()
                ->whereNotNull('layout_data')
                ->orderBy('id')
                ->chunkById((int) $this->option('chunk'), function ($courses) use ($apply, $limit) {
                    foreach ($courses as $course) {
                        $this->consider($course, $apply);

                        if ($limit > 0 && $this->stats['courses_scanned'] >= $limit) {
                            return false;
                        }
                    }

                    $this->tick(sprintf(
                        '  scanned: %d  changed: %d  colours: %d',
                        $this->stats['courses_scanned'],
                        $this->stats['courses_changed'],
                        $this->stats['colour_filled'],
                    ));

                    return true;
                });
        };

        if ($this->option('no-index')) {
            Course::withoutSyncingToSearch($run);
        } else {
            $run();
        }

        $this->newLine(2);
        $this->report($apply);

        if ($path = $this->option('csv')) {
            $this->writeCsv((string) $path);
        }

        return self::SUCCESS;
    }

    private function consider(Course $course, bool $apply): void
    {
        $this->stats['courses_scanned']++;

        $data = $course->layout_data;

        if (! is_array($data) || ! is_array($data['teeboxes'] ?? null) || $data['teeboxes'] === []) {
            return;
        }

        $overwrite = (bool) $this->option('overwrite');
        $touched = false;

        foreach ($data['teeboxes'] as $i => $tee) {
            $this->stats['teeboxes_scanned']++;

            $name = trim((string) ($tee['name'] ?? ''));

            if ($name === '') {
                $this->stats['unnamed']++;

                continue;
            }

            $hasColour = ($tee['color'] ?? null) !== null && $tee['color'] !== '';

            if ($hasColour && ! $overwrite) {
                $this->stats['already_set']++;

                continue;
            }

            $resolved = TeeColor::resolve($name);

            if ($resolved['color'] === null) {
                $this->stats['unresolved']++;
                $key = mb_strtolower($name);
                $this->unrecognised[$key] = ($this->unrecognised[$key] ?? 0) + 1;

                continue;
            }

            $before = $tee['color'] ?? null;
            $beforeSecond = $tee['secondaryColor'] ?? null;

            if ($resolved['color'] === $before && $resolved['secondaryColor'] === $beforeSecond) {
                $this->stats['already_set']++;

                continue;
            }

            $data['teeboxes'][$i]['color'] = $resolved['color'];
            $hasColour ? $this->stats['overwritten']++ : $this->stats['colour_filled']++;

            // Only ever adds a second colour; never clears one a human set on a
            // tee whose name happens to name a single colour.
            if ($resolved['secondaryColor'] !== null && ($beforeSecond === null || $overwrite)) {
                $data['teeboxes'][$i]['secondaryColor'] = $resolved['secondaryColor'];
                $this->stats['second_colour_filled']++;
            }

            $touched = true;

            $this->changes[] = [
                'course_id' => $course->id,
                'course_name' => (string) $course->course_name,
                'tee' => $name,
                'old_color' => $before,
                'new_color' => $resolved['color'],
                'old_secondary' => $beforeSecond,
                'new_secondary' => $data['teeboxes'][$i]['secondaryColor'] ?? null,
            ];
        }

        if (! $touched) {
            return;
        }

        $this->stats['courses_changed']++;

        if ($apply) {
            $course->layout_data = self::preservingObjects($data);
            $course->save();
        }
    }

    /**
     * Keep the JSON shapes the layout writer guarantees.
     *
     * `holes` is written as an object and 177 courses store it empty. Decoding
     * to an associative array and re-encoding would silently turn those `{}`
     * into `[]` — harmless to read, but a structural change this command has no
     * business making.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function preservingObjects(array $data): array
    {
        foreach ($data['teeboxes'] ?? [] as $i => $tee) {
            if (($tee['holes'] ?? null) === []) {
                $data['teeboxes'][$i]['holes'] = (object) [];
            }
        }

        if (($data['green_centers'] ?? null) === []) {
            $data['green_centers'] = (object) [];
        }

        return $data;
    }

    private function tick(string $message): void
    {
        static $n = 0;

        if (++$n % 5 === 0) {
            $this->output->write("\r{$message}   ");
        }
    }

    private function report(bool $apply): void
    {
        $this->info($apply ? 'Applied.' : 'Dry run — nothing written. Re-run with --apply.');

        $this->table(['metric', 'count'], collect($this->stats)->map(fn ($v, $k) => [$k, $v])->values()->all());

        foreach (array_slice($this->changes, 0, 10) as $c) {
            $this->line(sprintf(
                '  #%-6s %-34s %-18s %s%s',
                $c['course_id'],
                mb_substr($c['course_name'], 0, 34),
                mb_substr($c['tee'], 0, 18),
                $c['new_color'],
                $c['new_secondary'] ? ' + '.$c['new_secondary'] : '',
            ));
        }

        if (count($this->changes) > 10) {
            $this->line('  … '.(count($this->changes) - 10).' more (use --csv to see them all)');
        }

        if (! $this->unrecognised) {
            return;
        }

        // The actionable half of the report: these are the words to add to
        // config/tee_colors.php if any of them turn out to be colours.
        arsort($this->unrecognised);

        $this->newLine();
        $this->line('Names the vocabulary did not recognise:');

        foreach (array_slice($this->unrecognised, 0, 15, true) as $name => $count) {
            $this->line(sprintf('  %-30s %d', mb_substr($name, 0, 30), $count));
        }

        if (count($this->unrecognised) > 15) {
            $this->line('  … '.(count($this->unrecognised) - 15).' more distinct names');
        }
    }

    private function writeCsv(string $path): void
    {
        if (! $this->changes) {
            $this->warn('No changes to write.');

            return;
        }

        // "--csv=~/out.csv" arrives with a literal tilde.
        if (str_starts_with($path, '~/') && ($home = getenv('HOME'))) {
            $path = $home.substr($path, 1);
        }

        $fh = @fopen($path, 'w');

        if ($fh === false) {
            $this->error("Could not write the CSV to {$path} — the run itself was unaffected.");

            return;
        }

        fputcsv($fh, array_keys($this->changes[0]));
        foreach ($this->changes as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        $this->info('Wrote '.count($this->changes)." rows to {$path}");
    }
}
