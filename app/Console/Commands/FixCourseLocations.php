<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use App\Support\AddressComponents;
use App\Support\GeoResolver;
use App\Support\LayoutCoordinates;
use App\Support\ReverseGeocoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs course locations using the same path the editor's place picker uses:
 * Google's address components → GeoResolver::fromAddressComponents().
 *
 * Two candidate sets, both driven by a coordinate rather than a course name —
 * asking "what is the address of this point?" is unambiguous, where searching
 * "Spring Valley Golf Course" matches half a dozen states.
 *
 *   suspect  courses whose stored address disagrees with their stored
 *            state/country. Relations are corrected; the address is never
 *            overwritten, and a coordinate that contradicts the address is
 *            flagged for review instead of guessed at.
 *   missing  courses with no address at all, whose coordinate is recovered from
 *            green/hole geometry in layout_data. These gain an address, a postal
 *            code, real lat/lng, and the full trio.
 *
 * Every response is cached, so --cache-only replays a completed local run
 * against production with no further API spend.
 */
class FixCourseLocations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'courses:fix-locations
        {--apply : Write the changes (default is a dry run)}
        {--only=all : Which set to process: suspect, missing, or all}
        {--cache-only : Never call the API; replay cached responses (for production)}
        {--threshold=50 : Max distance (km) to accept a fallback city_id}
        {--sleep=100 : Milliseconds between API calls}
        {--limit=0 : Stop after this many courses (0 = no limit)}
        {--no-index : Skip Scout syncing during the run (reindex separately afterwards)}
        {--chunk=500 : Courses per batch}
        {--csv= : Write every proposed change to this path}';

    /**
     * @var string
     */
    protected $description = 'Fix course locations via Google reverse geocoding, using the editor\'s own resolution path.';

    private array $stats = [
        'suspect_scanned' => 0,
        'missing_scanned' => 0,
        'skipped_manual' => 0,
        'no_coordinate' => 0,
        'geocode_failed' => 0,
        'cache_miss' => 0,
        'contradicts_address' => 0,
        'unchanged' => 0,
        'relations_fixed' => 0,
        'address_filled' => 0,
        'coords_filled' => 0,
        'kept_finer_location' => 0,
        'borrowed_from_sibling' => 0,
        'written' => 0,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $changes = [];

    private array $names = [];

    public function handle(AddressComponents $parser, GeoResolver $geo): int
    {
        $apply = (bool) $this->option('apply');
        $cacheOnly = (bool) $this->option('cache-only');
        $only = (string) $this->option('only');
        $threshold = (float) $this->option('threshold');
        $limit = (int) $this->option('limit');

        $key = (string) config('services.google.geocoding_key');
        if ($key === '' && ! $cacheOnly) {
            $this->error('Missing GOOGLE_GEOCODING_API_KEY. It must be a server key (no referer restriction) with the Geocoding API enabled.');

            return self::FAILURE;
        }

        $rg = new ReverseGeocoder($key, $cacheOnly, (int) $this->option('sleep'));
        $this->names = $this->nameLookups();

        DB::disableQueryLog();

        $run = function () use ($only, $parser, $geo, $rg, $threshold, $apply, $limit) {
            if ($only === 'all' || $only === 'suspect') {
                $this->runSuspects($parser, $geo, $rg, $threshold, $apply, $limit);
            }

            if ($only === 'all' || $only === 'missing') {
                $this->borrowFromSiblings($apply);
                $this->runMissing($geo, $rg, $threshold, $apply, $limit);
            }
        };

        try {
            // Scout syncs inline unless SCOUT_QUEUE is on, so a thousand saves is
            // a thousand blocking Algolia round-trips. On a shared host that is
            // the difference between a run that finishes and an SSH session that
            // dies holding the connection. Reindex afterwards instead.
            if ($this->option('no-index')) {
                Course::withoutSyncingToSearch($run);
            } else {
                $run();
            }
        } catch (\RuntimeException $e) {
            $this->newLine(2);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine(2);
        $this->report($apply, $cacheOnly, $rg);

        if ($path = $this->option('csv')) {
            $this->writeCsv((string) $path);
        }

        return self::SUCCESS;
    }

    /**
     * Set 1: the address and the stored relations disagree. The coordinate is the
     * tiebreaker — but only when Google agrees with the address about the state.
     */
    private function runSuspects(AddressComponents $parser, GeoResolver $geo, ReverseGeocoder $rg, float $threshold, bool $apply, int $limit): void
    {
        $this->line('Set 1: courses whose address disagrees with their stored location');

        Course::query()
            ->whereNotNull('lat')->whereNotNull('lng')
            ->whereNotNull('address')->where('address', '!=', '')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($courses) use ($parser, $geo, $rg, $threshold, $apply, $limit) {
                foreach ($courses as $course) {
                    if ($limit > 0 && $this->stats['written'] >= $limit) {
                        return false;
                    }

                    if ($course->geo_source === 'manual') {
                        $this->stats['skipped_manual']++;

                        continue;
                    }

                    // Cheap local filter first — only pay for a lookup when the
                    // stored address and the stored relations actually disagree.
                    $parts = $parser->parse($course->address);
                    if ($parts['country_code'] === null && $parts['state_code'] === null) {
                        continue;
                    }

                    $fromAddress = $geo->fromAddressComponents($parts, (float) $course->lat, (float) $course->lng, $threshold);
                    if ($fromAddress === null
                        || ($fromAddress->state_id === $course->state_prov_id && $fromAddress->country_id === $course->country_id)) {
                        continue;
                    }

                    $this->stats['suspect_scanned']++;
                    $this->resolveSuspect($course, $parts, $fromAddress, $geo, $rg, $threshold, $apply);
                    $this->tick("  checked: {$this->stats['suspect_scanned']}  fixed: {$this->stats['relations_fixed']}");
                }

                return true;
            });

        $this->newLine();
    }

    private function resolveSuspect(Course $course, array $parts, object $fromAddress, GeoResolver $geo, ReverseGeocoder $rg, float $threshold, bool $apply): void
    {
        $components = $rg->lookup((float) $course->lat, (float) $course->lng);

        if ($components === null) {
            $this->stats[$rg->cacheMisses > 0 && $this->option('cache-only') ? 'cache_miss' : 'geocode_failed']++;

            return;
        }

        $match = $geo->fromAddressComponents($components, (float) $course->lat, (float) $course->lng, $threshold);

        if ($match === null || $match->country_id === null || $match->state_id === null) {
            $this->stats['geocode_failed']++;

            return;
        }

        // Google read the coordinate as a different state than the address claims.
        // One of the two is wrong and we can't tell which — that's a human call.
        //
        // Only when the address actually *asserted* a subdivision, though. Plenty
        // of countries don't encode one (Puerto Rico writes "Río Grande, 00745,
        // Puerto Rico"), in which case the address-side state came from a
        // nearest-city guess and comparing it to Google would flag agreement as
        // conflict. With no assertion to weigh against, the coordinate wins.
        $addressAssertedState = $parts['state_code'] !== null;

        if ($addressAssertedState && $match->state_id !== $fromAddress->state_id) {
            $this->stats['contradicts_address']++;
            $this->record($course, $match, 'contradicts-address', null);

            if ($apply) {
                $course->needs_review = true;
                $course->save();
            }

            return;
        }

        $before = [$course->city_id, $course->state_prov_id, $course->country_id];
        $after = [$match->id, $match->state_id, $match->country_id];

        if ($before === $after) {
            $this->stats['unchanged']++;

            return;
        }

        $this->stats['relations_fixed']++;
        $this->record($course, $match, 'relations-fixed', $before);

        if (! $apply) {
            return;
        }

        // The address is left alone here: we came looking for a bad relation, not
        // a bad address, and a wrong coordinate must not be able to destroy a
        // good one.
        $course->city_id = $after[0];
        $course->state_prov_id = $after[1];
        $course->country_id = $after[2];
        $course->geo_source = 'google';
        $course->geo_confidence = 0.950;
        $course->needs_review = false;
        $course->save();

        $this->stats['written']++;
        $this->reindex($before, $after);
    }

    /**
     * Set 2: no address at all. The coordinate comes from the course's own green
     * and hole geometry, so there is nothing to contradict — write everything.
     */
    private function runMissing(GeoResolver $geo, ReverseGeocoder $rg, float $threshold, bool $apply, int $limit): void
    {
        $this->line('Set 2: courses with no address, located from layout_data geometry');

        Course::query()
            ->where(fn ($q) => $q->whereNull('address')->orWhere('address', ''))
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($courses) use ($geo, $rg, $threshold, $apply, $limit) {
                foreach ($courses as $course) {
                    if ($limit > 0 && $this->stats['written'] >= $limit) {
                        return false;
                    }

                    if ($course->geo_source === 'manual') {
                        $this->stats['skipped_manual']++;

                        continue;
                    }

                    $this->stats['missing_scanned']++;

                    $coord = LayoutCoordinates::find($course->layout_data);
                    if ($coord === null) {
                        $this->stats['no_coordinate']++;

                        continue;
                    }

                    $this->resolveMissing($course, $coord, $geo, $rg, $threshold, $apply);
                    $this->tick("  checked: {$this->stats['missing_scanned']}  filled: {$this->stats['address_filled']}");
                }

                return true;
            });

        $this->newLine();
    }

    private function resolveMissing(Course $course, array $coord, GeoResolver $geo, ReverseGeocoder $rg, float $threshold, bool $apply): void
    {
        [$lat, $lng] = $coord;

        $components = $rg->lookup($lat, $lng);
        if ($components === null) {
            $this->stats[$this->option('cache-only') ? 'cache_miss' : 'geocode_failed']++;

            return;
        }

        $match = $geo->fromAddressComponents($components, $lat, $lng, $threshold);
        if ($match === null || $match->country_id === null) {
            $this->stats['geocode_failed']++;

            return;
        }

        $before = [$course->city_id, $course->state_prov_id, $course->country_id];
        $after = [$match->id, $match->state_id, $match->country_id];

        // Never trade a specific location for a vaguer one. Google's
        // administrative_area_level_1 is the top-level subdivision, and in a lot
        // of countries that is not the level dr5hn hangs cities off: England,
        // Scotland and Catalonia all have zero city rows, so resolving there
        // would blank a course that already knew it was in Dalkeith, Midlothian.
        //
        // The address and coordinates are still pure gains, so keep those and
        // leave the relations alone. (The suspect pass deliberately does allow a
        // null city — there the whole point is that the state was wrong.)
        if ($after[0] === null && $before[0] !== null) {
            $after = $before;
            $this->stats['kept_finer_location']++;
        }

        $this->stats['address_filled']++;
        if ($course->lat === null || $course->lng === null) {
            $this->stats['coords_filled']++;
        }
        $this->record($course, $match, 'address-filled', $before, $components['formatted_address'] ?? null);

        if (! $apply) {
            return;
        }

        $course->address = self::cleanAddress($components['formatted_address']) ?: $course->address;
        $course->postal_code = $course->postal_code ?: ($components['postal_code'] ?? null);
        $course->lat = $lat;
        $course->lng = $lng;
        $course->city_id = $after[0];
        $course->state_prov_id = $after[1];
        $course->country_id = $after[2];
        $course->geo_source = 'google';
        $course->geo_confidence = 0.900;
        $course->needs_review = false;
        $course->save();

        $this->stats['written']++;
        $this->reindex($before, $after);
    }

    /**
     * Free tier: a course with no address and no geometry can still inherit from
     * a same-club sibling, but only when every located sibling agrees.
     */
    private function borrowFromSiblings(bool $apply): void
    {
        $orphans = Course::query()
            ->where(fn ($q) => $q->whereNull('address')->orWhere('address', ''))
            ->whereNotNull('club_name')->where('club_name', '!=', '')
            ->where(fn ($q) => $q->whereNull('geo_source')->orWhere('geo_source', '!=', 'manual'))
            ->get()
            ->filter(fn (Course $c) => LayoutCoordinates::find($c->layout_data) === null);

        foreach ($orphans as $course) {
            $siblings = Course::where('club_name', $course->club_name)
                ->where('id', '!=', $course->id)
                ->whereNotNull('address')->where('address', '!=', '')
                ->whereNotNull('lat')->whereNotNull('city_id')
                ->select('city_id', 'state_prov_id', 'country_id')
                ->distinct()->get();

            if ($siblings->count() !== 1) {
                continue;
            }

            $s = $siblings->first();
            $before = [$course->city_id, $course->state_prov_id, $course->country_id];
            $after = [$s->city_id, $s->state_prov_id, $s->country_id];

            if ($before === $after) {
                continue;
            }

            $this->stats['borrowed_from_sibling']++;
            $this->record($course, (object) ['id' => $after[0], 'state_id' => $after[1], 'country_id' => $after[2]], 'borrowed', $before);

            if (! $apply) {
                continue;
            }

            $course->city_id = $after[0];
            $course->state_prov_id = $after[1];
            $course->country_id = $after[2];
            $course->geo_source = 'borrowed';
            $course->geo_confidence = 0.800;
            $course->save();

            $this->stats['written']++;
            $this->reindex($before, $after);
        }
    }

    /**
     * Strips a leading Open Location Code ("plus code").
     *
     * Google returns one when a coordinate has no street address, which is the
     * norm for a point in the middle of a fairway — 443 of the 964 located this
     * way came back as "P27G+9M Mount Martha VIC, Australia". The code itself is
     * machine noise in a field we render on the course page and in JSON-LD; what
     * follows it is a real place name, so keep that and drop the code.
     */
    public static function cleanAddress(?string $address): ?string
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        // The Open Location Code alphabet is a fixed 20 characters.
        $stripped = preg_replace(
            '/^[23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3}\s*,?\s*/i',
            '',
            trim($address)
        );

        $stripped = trim((string) $stripped, " \t,");

        return $stripped === '' ? null : $stripped;
    }

    /**
     * Throttled progress. A bare \r per row floods a redirected log with
     * thousands of lines, which is how these runs are usually captured.
     */
    private function tick(string $message): void
    {
        static $n = 0;

        if (++$n % 25 === 0) {
            $this->output->write("\r{$message}   ");
        }
    }

    private function record(Course $course, object $match, string $status, ?array $before, ?string $newAddress = null): void
    {
        $before ??= [$course->city_id, $course->state_prov_id, $course->country_id];

        $this->changes[] = [
            'status' => $status,
            'course_id' => $course->id,
            'name' => $course->club_name ?: $course->course_name,
            'old_address' => $course->address,
            'new_address' => $newAddress,
            'old_city' => $this->names['city'][$before[0]] ?? null,
            'new_city' => $this->names['city'][$match->id] ?? null,
            'old_state' => $this->names['state'][$before[1]] ?? null,
            'new_state' => $this->names['state'][$match->state_id] ?? null,
            'old_country' => $this->names['country'][$before[2]] ?? null,
            'new_country' => $this->names['country'][$match->country_id] ?? null,
            'old_ids' => implode('/', array_map(fn ($v) => $v ?? '-', $before)),
            'new_ids' => ($match->id ?? '-').'/'.($match->state_id ?? '-').'/'.($match->country_id ?? '-'),
        ];
    }

    private function reindex(array $before, array $after): void
    {
        if ($this->option('no-index')) {
            return;
        }

        try {
            foreach (array_unique(array_filter([$before[0], $after[0]])) as $id) {
                City::find($id)?->searchable();
            }
            foreach (array_unique(array_filter([$before[1], $after[1]])) as $id) {
                State::find($id)?->searchable();
            }
            foreach (array_unique(array_filter([$before[2], $after[2]])) as $id) {
                Country::find($id)?->searchable();
            }
        } catch (\Throwable) {
            // Non-fatal: a search hiccup must not abort the run.
        }
    }

    /** @return array{city:array<int,string>, state:array<int,string>, country:array<int,string>} */
    private function nameLookups(): array
    {
        return [
            'city' => DB::table('cities')->pluck('name', 'id')->all(),
            'state' => DB::table('states')->pluck('name', 'id')->all(),
            'country' => DB::table('countries')->pluck('name', 'id')->all(),
        ];
    }

    private function report(bool $apply, bool $cacheOnly, ReverseGeocoder $rg): void
    {
        $this->info($apply ? 'Applied.' : 'Dry run — nothing written. Re-run with --apply.');

        $this->table(['metric', 'count'], collect($this->stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line("  API calls: {$rg->calls}   cache hits: {$rg->cacheHits}   cache misses: {$rg->cacheMisses}");

        if ($cacheOnly && $rg->cacheMisses > 0) {
            $this->warn("  {$rg->cacheMisses} coordinates were not in the cache — this database has rows the cache was not built for.");
            $this->warn('  Re-run those without --cache-only, or ship a newer cache.');
        }

        $flagged = array_filter($this->changes, fn ($c) => $c['status'] === 'contradicts-address');
        if ($flagged) {
            $this->newLine();
            $this->warn('Flagged for review (the coordinate and the address name different states):');
            foreach (array_slice($flagged, 0, 10) as $c) {
                $this->line("  #{$c['course_id']}  {$c['name']}");
                $this->line("      address says: {$c['old_address']}");
                $this->line("      coordinate is in: {$c['new_city']}, {$c['new_state']}, {$c['new_country']}");
            }
        }
    }

    private function writeCsv(string $path): void
    {
        if (! $this->changes) {
            $this->warn('No changes to write.');

            return;
        }

        $fh = fopen($path, 'w');
        fputcsv($fh, array_keys($this->changes[0]));
        foreach ($this->changes as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        $this->info('Wrote '.count($this->changes)." rows to {$path}");
    }
}
