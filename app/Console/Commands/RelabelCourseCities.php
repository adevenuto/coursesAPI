<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Course;
use App\Support\AddressComponents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Points city_id at the city a course's address actually names.
 *
 * city_id was assigned by nearest centroid, so a course whose address reads
 * "2035 Galbraith Rd, Cincinnati, OH" is stored and served as being in Skyline
 * Acres — a 1,700-person census area that happens to sit closer to the clubhouse
 * than downtown Cincinnati's centroid does. Not wrong, just useless as a label,
 * and it makes `address` and `location.city` contradict each other in the same
 * API response.
 *
 * This only ever refines a label. The candidate is scoped to the course's stored
 * state, so state_prov_id and country_id can never move, and nothing here
 * re-derives a position.
 */
class RelabelCourseCities extends Command
{
    /**
     * @var string
     */
    protected $signature = 'courses:relabel-cities
        {--apply : Write the changes (default is a dry run)}
        {--min-ratio=3 : The named city must be this many times larger}
        {--min-population=10000 : ...and at least this large}
        {--max-km=50 : Reject a same-named city further than this from the course}
        {--chunk=1000 : Courses processed per batch}
        {--no-index : Skip Scout syncing during the run (reindex separately)}
        {--csv= : Write every proposed change to this path}';

    /**
     * @var string
     */
    protected $description = 'Relabel courses to the city their address names, when it is materially larger than the stored one.';

    private array $stats = [
        'scanned' => 0,
        'skipped_manual' => 0,
        'no_candidate' => 0,
        'already_matches' => 0,
        'name_not_in_state' => 0,
        'too_far' => 0,
        'not_big_enough' => 0,
        'relabelled' => 0,
        'written' => 0,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $changes = [];

    /** city id => how many courses we moved away from it */
    private array $vacated = [];

    public function handle(AddressComponents $parser): int
    {
        $apply = (bool) $this->option('apply');
        $ratio = (float) $this->option('min-ratio');
        $minPop = (int) $this->option('min-population');
        $maxKm = (float) $this->option('max-km');

        DB::disableQueryLog();

        $run = function () use ($parser, $ratio, $minPop, $maxKm, $apply) {
            Course::query()
                ->whereNotNull('address')->where('address', '!=', '')
                ->whereNotNull('city_id')->whereNotNull('state_prov_id')
                ->orderBy('id')
                ->chunkById((int) $this->option('chunk'), function ($courses) use ($parser, $ratio, $minPop, $maxKm, $apply) {
                    foreach ($courses as $course) {
                        $this->consider($course, $parser, $ratio, $minPop, $maxKm, $apply);
                    }
                    $this->tick("  scanned: {$this->stats['scanned']}  relabelled: {$this->stats['relabelled']}");
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

    private function consider(Course $course, AddressComponents $parser, float $ratio, int $minPop, float $maxKm, bool $apply): void
    {
        $this->stats['scanned']++;

        // A human picked this one in the editor.
        if ($course->geo_source === 'manual') {
            $this->stats['skipped_manual']++;

            return;
        }

        $candidate = $parser->parse($course->address)['city_candidates'][0] ?? null;

        // Addresses with no subdivision code yield no candidate, which is what
        // keeps this to well-structured US/CA/AU rows.
        if ($candidate === null || trim($candidate) === '') {
            $this->stats['no_candidate']++;

            return;
        }

        $stored = DB::table('cities')->where('id', $course->city_id)->first(['id', 'name', 'population']);

        if ($stored === null) {
            $this->stats['no_candidate']++;

            return;
        }

        if (mb_strtolower(trim($candidate)) === mb_strtolower((string) $stored->name)) {
            $this->stats['already_matches']++;

            return;
        }

        // Scoped to the stored state: this is what makes the command incapable of
        // changing anything but the label.
        $match = $this->nearestNamed($course, $candidate, $maxKm);

        if ($match === 'missing') {
            $this->stats['name_not_in_state']++;

            return;
        }

        if ($match === 'far') {
            $this->stats['too_far']++;

            return;
        }

        $newPop = (int) $match->population;
        $oldPop = (int) $stored->population;

        // Also the guard against moving backwards to a smaller place.
        if ($newPop < $minPop || $newPop <= $oldPop * $ratio) {
            $this->stats['not_big_enough']++;

            return;
        }

        $this->stats['relabelled']++;
        $this->vacated[$stored->id] = ($this->vacated[$stored->id] ?? 0) + 1;

        $this->changes[] = [
            'course_id' => $course->id,
            'name' => $course->club_name ?: $course->course_name,
            'address' => $course->address,
            'old_city' => $stored->name,
            'old_population' => $oldPop,
            'old_city_id' => $stored->id,
            'new_city' => $match->name,
            'new_population' => $newPop,
            'new_city_id' => $match->id,
            'km' => round((float) $match->km, 1),
        ];

        if (! $apply) {
            return;
        }

        // city_id and nothing else. The position itself still came from wherever
        // it came from; we are only renaming it.
        $course->city_id = (int) $match->id;
        $course->save();

        $this->stats['written']++;
    }

    /**
     * Nearest city of this name inside the course's stored state.
     *
     * Two Springfields in one state are different places, so proximity decides
     * which one the address means — and only then does size decide whether it is
     * worth adopting.
     *
     * @return object|string the city row, or 'missing' / 'far'
     */
    private function nearestNamed(Course $course, string $name, float $maxKm): object|string
    {
        $lat = $course->lat !== null ? (float) $course->lat : null;
        $lng = $course->lng !== null ? (float) $course->lng : null;

        $query = DB::table('cities')
            ->where('state_id', $course->state_prov_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);

        if ($lat === null || $lng === null) {
            $city = $query->selectRaw('id, name, population, 0 AS km')->orderByDesc('population')->first();

            return $city ?? 'missing';
        }

        $city = $query->selectRaw(
            'id, name, population, '.
            '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) '.
            '+ sin(radians(?)) * sin(radians(latitude))))) AS km',
            [$lat, $lng, $lat]
        )->orderBy('km')->first();

        if ($city === null) {
            return 'missing';
        }

        return (float) $city->km > $maxKm ? 'far' : $city;
    }

    /**
     * Cities we moved the last course away from.
     *
     * City::shouldBeSearchable() already returns false once a city has no
     * courses, but a has('courses') reindex will not DELETE an existing
     * document — the explorer would keep offering an empty "Skyline Acres" page.
     * So they need an explicit unsearchable().
     *
     * @return array<int, int>
     */
    private function emptiedCities(): array
    {
        if (! $this->vacated) {
            return [];
        }

        $counts = DB::table('courses')
            ->select('city_id', DB::raw('count(*) n'))
            ->whereIn('city_id', array_keys($this->vacated))
            ->groupBy('city_id')
            ->pluck('n', 'city_id');

        // On a dry run nothing moved yet, so subtract what we would have moved.
        $applied = (bool) $this->option('apply');

        return collect($this->vacated)
            ->filter(function ($moved, $cityId) use ($counts, $applied) {
                $remaining = (int) ($counts[$cityId] ?? 0);

                return $applied ? $remaining === 0 : $remaining - $moved <= 0;
            })
            ->keys()->map(fn ($id) => (int) $id)->all();
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
            $this->line("  #{$c['course_id']}  {$c['name']}");
            $this->line("      {$c['address']}");
            $this->line(sprintf(
                '      %s (%s)  →  %s (%s), %skm away',
                $c['old_city'], number_format($c['old_population']),
                $c['new_city'], number_format($c['new_population']), $c['km']
            ));
        }

        if (count($this->changes) > 10) {
            $this->line('  … '.(count($this->changes) - 10).' more (use --csv to see them all)');
        }

        $emptied = $this->emptiedCities();

        if (! $emptied) {
            return;
        }

        $this->newLine();
        $this->warn(count($emptied).' cities are left with no courses and must leave the search index.');

        if ($apply && ! $this->option('no-index')) {
            City::whereIn('id', $emptied)->get()->each->unsearchable();
            $this->info('  Removed them from the index.');

            return;
        }

        $this->line('  '.implode(',', $emptied));
        $this->line('  Remove with: City::whereIn("id", [...])->get()->each->unsearchable();');
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
