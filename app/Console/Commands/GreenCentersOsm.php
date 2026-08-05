<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GreenCentersOsm extends Command
{
    /**
     * @var string
     */
    protected $signature = 'green-centers:osm
        {--limit=0 : Cap courses processed (0 = all)}
        {--radius=1500 : Overpass search radius in meters}
        {--sleep=1200 : Milliseconds between live Overpass calls}
        {--max-green-dist=60 : Max meters from a hole-way end to accept its green}
        {--validate : Test-only vs courses that already have greenCenters; writes nothing}';

    /**
     * @var string
     */
    protected $description = 'Fill missing greenCenters from OpenStreetMap — strict: clean hole numbering + par-validated only.';

    private array $stats = [
        'processed' => 0, 'written' => 0,
        'skip_no_greens' => 0, 'skip_numbering' => 0, 'skip_par' => 0,
        'skip_assign' => 0, 'skip_error' => 0,
    ];

    public function handle(): int
    {
        $radius = (int) $this->option('radius');
        $maxDist = (float) $this->option('max-green-dist');
        $limit = (int) $this->option('limit');

        DB::disableQueryLog();

        if ($this->option('validate')) {
            return $this->runValidation($radius, $maxDist, $limit);
        }

        $q = DB::table('courses')
            ->where('layout_data', 'not like', '%greenCenters%')
            ->whereNotNull('layout_data')
            ->whereNotNull('lat')->whereNotNull('lng')
            ->orderBy('id');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $courses = $q->get(['id', 'course_name', 'lat', 'lng', 'layout_data']);

        $bar = $this->output->createProgressBar($courses->count());
        $bar->start();

        foreach ($courses as $c) {
            $this->processCourse($c, $radius, $maxDist);
            $this->stats['processed']++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('OSM green-center extraction complete.');
        $this->table(
            ['processed', 'written', 'no greens', 'numbering', 'par', 'assign', 'errors'],
            [[
                $this->stats['processed'], $this->stats['written'],
                $this->stats['skip_no_greens'], $this->stats['skip_numbering'],
                $this->stats['skip_par'], $this->stats['skip_assign'], $this->stats['skip_error'],
            ]]
        );

        return self::SUCCESS;
    }

    /**
     * Run the exact strict pipeline against courses that ALREADY have
     * greenCenters, and compare per-hole. No writes. Proves accuracy + that the
     * green->hole assignment is correct (gross mismatches = wrong assignment).
     */
    private function runValidation(int $radius, float $maxDist, int $limit): int
    {
        $courses = DB::table('courses')
            ->where('layout_data', 'like', '%greenCenters%')
            ->whereNotNull('lat')->whereNotNull('lng')
            ->inRandomOrder()->limit($limit > 0 ? $limit : 40)
            ->get(['id', 'course_name', 'lat', 'lng', 'layout_data']);

        $passed = 0;
        $errors = [];
        $gross = 0;
        $within15 = 0;

        $bar = $this->output->createProgressBar($courses->count());
        $bar->start();
        foreach ($courses as $c) {
            $bar->advance();
            $data = json_decode($c->layout_data, true);
            $truth = $data['greenCenters'] ?? null;
            if (! is_array($truth)) {
                continue;
            }
            [$ourPar, $holeCount] = $this->ourPars($c->layout_data);
            if ($holeCount <= 0 || count($ourPar) < $holeCount) {
                continue;
            }
            $osm = $this->overpass((int) $c->id, (float) $c->lat, (float) $c->lng, $radius);
            if ($osm === null || $osm['greens'] === []) {
                continue;
            }
            $refs = array_keys($osm['holeWays']);
            sort($refs);
            if ($osm['dup_refs'] || $refs !== range(1, $holeCount) || count($osm['greens']) < $holeCount) {
                continue;
            }
            $parOk = true;
            for ($h = 1; $h <= $holeCount; $h++) {
                $p = $osm['holeWays'][$h]['par'] ?? null;
                if ($p === null || (int) $p !== (int) $ourPar[$h]) {
                    $parOk = false;
                    break;
                }
            }
            if (! $parOk) {
                continue;
            }
            $gc = $this->assignGreens($osm['holeWays'], $osm['greens'], $holeCount, $maxDist);
            if ($gc === null) {
                continue;
            }
            $passed++;
            for ($h = 1; $h <= $holeCount; $h++) {
                $key = 'hole-'.$h;
                if (! isset($truth[$key]['lat'], $truth[$key]['lng'], $gc[$key])) {
                    continue;
                }
                $d = $this->haversine($gc[$key]['lat'], $gc[$key]['lng'], (float) $truth[$key]['lat'], (float) $truth[$key]['lng']);
                $errors[] = $d;
                if ($d <= 15) {
                    $within15++;
                }
                if ($d > 50) {
                    $gross++;
                }
            }
        }
        $bar->finish();
        $this->newLine(2);

        sort($errors);
        $n = count($errors);
        $median = $n ? $errors[intdiv($n, 2)] : 0;
        $p90 = $n ? $errors[min($n - 1, (int) floor($n * 0.9))] : 0;
        $max = $n ? end($errors) : 0;

        $this->info('VALIDATION vs existing ground truth (no writes):');
        $this->line("  courses that passed the strict pipeline: {$passed}");
        $this->line("  holes compared: {$n}");
        $this->line('  per-hole error  median: '.round($median, 1).'m  p90: '.round($p90, 1).'m  max: '.round($max, 1).'m');
        $this->line('  within 15m: '.($n ? round($within15 / $n * 100, 1) : 0).'%');
        $this->line("  GROSS mismatches (>50m, = wrong hole assignment): {$gross}");

        return self::SUCCESS;
    }

    private function processCourse(object $c, int $radius, float $maxDist): void
    {
        [$ourPar, $holeCount] = $this->ourPars($c->layout_data);
        if ($holeCount <= 0 || count($ourPar) < $holeCount) {
            $this->stats['skip_par']++;

            return;
        }

        $osm = $this->overpass((int) $c->id, (float) $c->lat, (float) $c->lng, $radius);
        if ($osm === null) {
            $this->stats['skip_error']++;

            return;
        }

        if ($osm['greens'] === []) {
            $this->stats['skip_no_greens']++;

            return;
        }

        // Numbering must be a clean 1..holeCount ref set (single course, complete).
        $refs = array_keys($osm['holeWays']);
        sort($refs);
        if ($osm['dup_refs'] || $refs !== range(1, $holeCount) || count($osm['greens']) < $holeCount) {
            $this->stats['skip_numbering']++;

            return;
        }

        // Every hole must have an OSM par that matches ours (reject on any gap/conflict).
        for ($h = 1; $h <= $holeCount; $h++) {
            $osmPar = $osm['holeWays'][$h]['par'] ?? null;
            if ($osmPar === null || (int) $osmPar !== (int) $ourPar[$h]) {
                $this->stats['skip_par']++;

                return;
            }
        }

        // Assign a green to each hole via the hole-way's green end. Strict 1:1 within threshold.
        $greenCenters = $this->assignGreens($osm['holeWays'], $osm['greens'], $holeCount, $maxDist);
        if ($greenCenters === null) {
            $this->stats['skip_assign']++;

            return;
        }

        $this->writeGreenCenters((int) $c->id, $greenCenters);
        $this->stats['written']++;
    }

    /**
     * @param  array<int,array{par:?int,coords:list<array{0:float,1:float}>}>  $holeWays
     * @param  list<array{0:float,1:float}>  $greens
     * @return array<string,array{lat:float,lng:float}>|null
     */
    private function assignGreens(array $holeWays, array $greens, int $holeCount, float $maxDist): ?array
    {
        // 1) The green END of each hole way = the endpoint closer to some green
        //    (the tee end sits near the PREVIOUS hole's green, so never use it).
        $greenEnd = [];
        for ($h = 1; $h <= $holeCount; $h++) {
            $coords = $holeWays[$h]['coords'];
            if (count($coords) < 2) {
                return null;
            }
            $a = $coords[0];
            $b = $coords[count($coords) - 1];
            $da = $this->nearestGreenDist($a, $greens);
            $db = $this->nearestGreenDist($b, $greens);
            $greenEnd[$h] = $da <= $db ? $a : $b;
        }

        // 2) Global nearest-pair matching (assign closest hole-green pairs first).
        $pairs = [];
        for ($h = 1; $h <= $holeCount; $h++) {
            foreach ($greens as $gi => $g) {
                $pairs[] = [$this->haversine($greenEnd[$h][0], $greenEnd[$h][1], $g[0], $g[1]), $h, $gi];
            }
        }
        usort($pairs, fn ($x, $y) => $x[0] <=> $y[0]);

        $out = [];
        $usedHole = [];
        $usedGreen = [];
        foreach ($pairs as [$d, $h, $gi]) {
            if (isset($usedHole[$h]) || isset($usedGreen[$gi]) || $d > $maxDist) {
                continue;
            }
            $usedHole[$h] = true;
            $usedGreen[$gi] = true;
            $out['hole-'.$h] = ['lat' => $greens[$gi][0], 'lng' => $greens[$gi][1]];
        }

        // Every hole must have gotten a distinct green within threshold.
        return count($out) === $holeCount ? $out : null;
    }

    /**
     * @param  array{0:float,1:float}  $pt
     * @param  list<array{0:float,1:float}>  $greens
     */
    private function nearestGreenDist(array $pt, array $greens): float
    {
        $min = INF;
        foreach ($greens as $g) {
            $d = $this->haversine($pt[0], $pt[1], $g[0], $g[1]);
            if ($d < $min) {
                $min = $d;
            }
        }

        return $min;
    }

    private function writeGreenCenters(int $id, array $greenCenters): void
    {
        // Re-read + re-assert the "no greenCenters yet" guard so we never overwrite.
        $row = DB::table('courses')->where('id', $id)->value('layout_data');
        $data = json_decode((string) $row, true);
        if (! is_array($data) || array_key_exists('greenCenters', $data)) {
            return;
        }
        $data['greenCenters'] = $greenCenters;
        $data['greenCentersSource'] = 'osm';

        DB::table('courses')->where('id', $id)->update([
            'layout_data' => json_encode($data),
        ]);
    }

    /**
     * Fetch hole ways (with geometry + par) and green centroids, cached by course id.
     *
     * @return array{greens:list<array{0:float,1:float}>,holeWays:array<int,array{par:?int,coords:list<array{0:float,1:float}>}>,dup_refs:bool}|null
     */
    private function overpass(int $courseId, float $lat, float $lng, int $radius): ?array
    {
        $cacheKey = 'overpass:'.$courseId;
        $cached = DB::table('geocode_cache')->where('query', $cacheKey)->first();
        if ($cached !== null && $cached->response_json !== null) {
            return $this->parseElements(json_decode($cached->response_json, true) ?? []);
        }

        $query = "[out:json][timeout:60];"
            ."(way[\"golf\"=\"hole\"](around:{$radius},{$lat},{$lng});"
            ."way[\"golf\"=\"green\"](around:{$radius},{$lat},{$lng});"
            ."relation[\"golf\"=\"green\"](around:{$radius},{$lat},{$lng}););"
            .'out tags geom;';

        $elements = $this->fetchWithRetry($query);
        if ($elements === null) {
            return null; // leave uncached so a re-run retries
        }

        DB::table('geocode_cache')->updateOrInsert(
            ['query' => $cacheKey],
            [
                'provider' => 'overpass',
                'response_json' => json_encode($elements),
                'status' => 'fetched',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        usleep(((int) $this->option('sleep')) * 1000);

        return $this->parseElements($elements);
    }

    private function fetchWithRetry(string $query): ?array
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $resp = Http::withHeaders(['User-Agent' => 'coursesApi-green-osm/1.0 (golf green centers)'])
                    ->timeout(90)->asForm()->post('https://overpass-api.de/api/interpreter', ['data' => $query]);
                if ($resp->successful()) {
                    $json = $resp->json();
                    if (isset($json['elements'])) {
                        return $json['elements'];
                    }
                }
            } catch (\Throwable) {
                // fall through to backoff
            }
            usleep(4000 * 1000 * $attempt); // 4s, 8s backoff
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $elements
     * @return array{greens:list<array{0:float,1:float}>,holeWays:array<int,array{par:?int,coords:list<array{0:float,1:float}>}>,dup_refs:bool}
     */
    private function parseElements(array $elements): array
    {
        $greens = [];
        $holeWays = [];
        $refSeen = [];
        $dup = false;

        foreach ($elements as $e) {
            $tags = $e['tags'] ?? [];
            $type = $tags['golf'] ?? '';
            if ($type === 'green') {
                $ctr = $this->centroid($e);
                if ($ctr !== null) {
                    $greens[] = $ctr;
                }
            } elseif ($type === 'hole' && isset($tags['ref']) && preg_match('/^\d+$/', trim($tags['ref']))) {
                $ref = (int) $tags['ref'];
                if (isset($refSeen[$ref])) {
                    $dup = true;
                }
                $refSeen[$ref] = true;
                $coords = [];
                foreach ($e['geometry'] ?? [] as $pt) {
                    if (isset($pt['lat'], $pt['lon'])) {
                        $coords[] = [(float) $pt['lat'], (float) $pt['lon']];
                    }
                }
                $holeWays[$ref] = ['par' => $tags['par'] ?? null, 'coords' => $coords];
            }
        }

        return ['greens' => $greens, 'holeWays' => $holeWays, 'dup_refs' => $dup];
    }

    /**
     * Centroid of an OSM green (way or multipolygon relation) from its geometry.
     *
     * @param  array<string,mixed>  $e
     * @return array{0:float,1:float}|null
     */
    private function centroid(array $e): ?array
    {
        $pts = [];
        foreach ($e['geometry'] ?? [] as $pt) {
            if (isset($pt['lat'], $pt['lon'])) {
                $pts[] = [(float) $pt['lat'], (float) $pt['lon']];
            }
        }
        // Relation greens carry per-member geometry instead.
        foreach ($e['members'] ?? [] as $m) {
            foreach ($m['geometry'] ?? [] as $pt) {
                if (isset($pt['lat'], $pt['lon'])) {
                    $pts[] = [(float) $pt['lat'], (float) $pt['lon']];
                }
            }
        }
        if ($pts === []) {
            return null;
        }
        $lat = array_sum(array_column($pts, 0)) / count($pts);
        $lng = array_sum(array_column($pts, 1)) / count($pts);

        return [$lat, $lng];
    }

    /**
     * @return array{0:array<int,int>,1:int}
     */
    private function ourPars(string $layoutJson): array
    {
        $d = json_decode($layoutJson, true);
        $holeCount = (int) ($d['hole_count'] ?? 0);
        $pars = [];
        if (! empty($d['teeboxes']) && is_array($d['teeboxes'])) {
            $best = null;
            foreach ($d['teeboxes'] as $tb) {
                if (isset($tb['holes']) && is_array($tb['holes']) && ($best === null || count($tb['holes']) > count($best))) {
                    $best = $tb['holes'];
                }
            }
            if ($best) {
                foreach ($best as $key => $h) {
                    if (preg_match('/(\d+)/', (string) $key, $m) && isset($h['par'])) {
                        $pars[(int) $m[1]] = (int) $h['par'];
                    }
                }
            }
        }
        if ($holeCount === 0) {
            $holeCount = count($pars);
        }

        return [$pars, $holeCount];
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * asin(min(1, sqrt($a)));
    }
}
