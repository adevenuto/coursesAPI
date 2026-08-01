<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GreenCentersOsmProbe extends Command
{
    /**
     * @var string
     */
    protected $signature = 'green-centers:osm-probe
        {--limit=60 : Number of no-greenCenter courses to sample}
        {--radius=1500 : Overpass search radius in meters}
        {--sleep=1500 : Milliseconds between Overpass calls}';

    /**
     * @var string
     */
    protected $description = 'READ-ONLY: measure OSM green coverage + authoritative hole-numbering feasibility. Writes nothing.';

    private array $cov = ['ge18' => 0, 'p9_17' => 0, 'p1_8' => 0, 'zero' => 0];

    private array $num = [
        'par_confirmed' => 0,  // full ref numbering + OSM par agrees with ours
        'ref_only' => 0,       // full ref numbering, no OSM par to cross-check
        'par_conflict' => 0,   // ref numbering but OSM par disagrees -> reject
        'greens_only' => 0,    // greens but no complete ref numbering
        'multi_course' => 0,   // duplicate refs (radius caught >1 course)
        'none' => 0,           // no greens
    ];

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $radius = (int) $this->option('radius');

        $courses = DB::table('courses')
            ->where('layout_data', 'not like', '%greenCenters%')
            ->whereNotNull('layout_data')
            ->whereNotNull('lat')->whereNotNull('lng')
            ->inRandomOrder()->limit($limit)
            ->get(['id', 'course_name', 'lat', 'lng', 'layout_data']);

        $this->info("Probing {$courses->count()} courses (radius {$radius}m)...");
        $examples = [];

        foreach ($courses as $c) {
            $osm = $this->overpass((float) $c->lat, (float) $c->lng, $radius);
            if ($osm === null) {
                $this->warn("  overpass error for {$c->course_name}");

                continue;
            }

            [$ourPar, $holeCount] = $this->ourPars($c->layout_data);
            $greens = $osm['greens'];

            // coverage bucket
            if ($greens >= 18) {
                $this->cov['ge18']++;
            } elseif ($greens >= 9) {
                $this->cov['p9_17']++;
            } elseif ($greens >= 1) {
                $this->cov['p1_8']++;
            } else {
                $this->cov['zero']++;
            }

            // numbering verdict
            $verdict = $this->verdict($osm, $ourPar, $holeCount, $greens);
            $this->num[$verdict]++;

            if (count($examples) < 12) {
                $examples[] = sprintf('  %-34s greens=%-3d holeRefs=%-3d -> %s',
                    mb_substr($c->course_name, 0, 34), $greens, $osm['ref_count'], $verdict);
            }

            usleep(((int) $this->option('sleep')) * 1000);
        }

        $this->newLine();
        $this->line('Examples:');
        foreach ($examples as $e) {
            $this->line($e);
        }

        $n = array_sum($this->cov);
        $this->newLine();
        $this->info("=== GREEN COVERAGE (n={$n}) ===");
        $this->table(['>=18', '9-17', '1-8', '0'],
            [[$this->pct($this->cov['ge18'], $n), $this->pct($this->cov['p9_17'], $n), $this->pct($this->cov['p1_8'], $n), $this->pct($this->cov['zero'], $n)]]);

        $this->info('=== NUMBERING (the decision metric) ===');
        $this->table(
            ['par-confirmed', 'ref-only (no OSM par)', 'par-CONFLICT (reject)', 'greens only', 'multi-course', 'no greens'],
            [[
                $this->pct($this->num['par_confirmed'], $n),
                $this->pct($this->num['ref_only'], $n),
                $this->pct($this->num['par_conflict'], $n),
                $this->pct($this->num['greens_only'], $n),
                $this->pct($this->num['multi_course'], $n),
                $this->pct($this->num['none'], $n),
            ]]
        );

        $usable = $this->num['par_confirmed'] + $this->num['ref_only'];
        $this->newLine();
        $this->line("Numbered & usable (par-confirmed + ref-only): <options=bold>{$this->pct($usable, $n)}</>");
        $this->line("  of which par-CONFIRMED (safest): <options=bold>{$this->pct($this->num['par_confirmed'], $n)}</>");

        return self::SUCCESS;
    }

    private function verdict(array $osm, array $ourPar, int $holeCount, int $greens): string
    {
        if ($greens === 0) {
            return 'none';
        }
        if ($osm['dup_refs']) {
            return 'multi_course';
        }
        // Authoritative numbering = refs exactly 1..holeCount, and enough greens.
        $refsComplete = $holeCount > 0
            && $osm['refs'] === range(1, $holeCount)
            && $greens >= $holeCount;
        if (! $refsComplete) {
            return 'greens_only';
        }

        // Cross-check par where OSM provides it.
        $osmPar = $osm['holes']; // ref => par
        $anyPar = false;
        for ($h = 1; $h <= $holeCount; $h++) {
            if (isset($osmPar[$h])) {
                $anyPar = true;
                if (! isset($ourPar[$h]) || (int) $osmPar[$h] !== (int) $ourPar[$h]) {
                    return 'par_conflict';
                }
            }
        }

        return $anyPar ? 'par_confirmed' : 'ref_only';
    }

    /**
     * @return array{0:array<int,int>,1:int} [ hole => par, holeCount ]
     */
    private function ourPars(string $layoutJson): array
    {
        $d = json_decode($layoutJson, true);
        $holeCount = (int) ($d['hole_count'] ?? 0);
        $pars = [];
        if (! empty($d['teeboxes']) && is_array($d['teeboxes'])) {
            // pick the teebox with the most holes
            $best = null;
            foreach ($d['teeboxes'] as $tb) {
                if (isset($tb['holes']) && is_array($tb['holes'])) {
                    if ($best === null || count($tb['holes']) > count($best)) {
                        $best = $tb['holes'];
                    }
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

    /**
     * @return array{greens:int,holes:array<int,int>,ref_count:int,dup_refs:bool}|null
     */
    private function overpass(float $lat, float $lng, int $radius): ?array
    {
        $q = "[out:json][timeout:40];"
            ."(way[\"golf\"=\"hole\"](around:{$radius},{$lat},{$lng});"
            ."way[\"golf\"=\"green\"](around:{$radius},{$lat},{$lng});"
            ."relation[\"golf\"=\"green\"](around:{$radius},{$lat},{$lng}););"
            .'out tags center;';

        try {
            $resp = Http::withHeaders(['User-Agent' => 'coursesApi-green-probe/1.0 (golf course green centers research)'])
                ->timeout(60)->asForm()->post('https://overpass-api.de/api/interpreter', ['data' => $q]);
            if ($resp->failed()) {
                return null;
            }
            $json = $resp->json();
            if (! isset($json['elements'])) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $greens = 0;
        $holes = [];      // ref => par
        $refSeen = [];
        $dup = false;
        foreach ($json['elements'] as $e) {
            $tags = $e['tags'] ?? [];
            $type = $tags['golf'] ?? '';
            if ($type === 'green') {
                $greens++;
            } elseif ($type === 'hole') {
                if (isset($tags['ref']) && preg_match('/^\d+$/', trim($tags['ref']))) {
                    $ref = (int) $tags['ref'];
                    if (isset($refSeen[$ref])) {
                        $dup = true;
                    }
                    $refSeen[$ref] = true;
                    if (isset($tags['par'])) {
                        $holes[$ref] = (int) $tags['par'];
                    }
                }
            }
        }
        $refs = array_keys($refSeen);
        sort($refs);

        return ['greens' => $greens, 'holes' => $holes, 'refs' => $refs, 'ref_count' => count($refSeen), 'dup_refs' => $dup];
    }

    private function pct(int $n, int $total): string
    {
        return $total > 0 ? $n.' ('.number_format($n / $total * 100, 1).'%)' : '0';
    }
}
