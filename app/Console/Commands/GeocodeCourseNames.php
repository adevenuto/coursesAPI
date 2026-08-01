<?php

namespace App\Console\Commands;

use App\Support\GeoResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GeocodeCourseNames extends Command
{
    /**
     * @var string
     */
    protected $signature = 'courses:geocode-names
        {--provider=nominatim : Geocoding provider}
        {--sleep=1100 : Milliseconds between live API calls}
        {--limit=0 : Cap the number of distinct names resolved (0 = all)}
        {--threshold=50 : Max km to accept a city_id match}';

    /**
     * @var string
     */
    protected $description = 'Map the remaining name-only courses by geocoding their club/course names (safe: only touches unmatched rows).';

    private GeoResolver $geo;

    private array $stats = [
        'names' => 0,
        'borrowed' => 0,
        'geocoded_rows' => 0,
        'flagged_rows' => 0,
    ];

    /** club_names known to be ambiguous (used across multiple cities anywhere). */
    private array $ambiguousNames = [];

    public function handle(): int
    {
        $this->geo = new GeoResolver;
        $threshold = (float) $this->option('threshold');
        $limit = (int) $this->option('limit');

        DB::disableQueryLog();
        $this->loadAmbiguousNames();

        // 1) Guarded borrow — copy location from unambiguous located siblings.
        $this->borrowFromSiblings();

        // 2) Distinct names still needing resolution (only unmatched rows).
        $names = $this->unmatched()
            ->select('club_name')
            ->whereNotNull('club_name')
            ->where('club_name', '!=', '')
            ->distinct()
            ->orderBy('club_name')
            ->pluck('club_name');

        if ($limit > 0) {
            $names = $names->take($limit);
        }

        $bar = $this->output->createProgressBar($names->count());
        $bar->start();

        foreach ($names as $clubName) {
            $this->stats['names']++;
            $this->resolveName($clubName, $threshold);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->report();

        return self::SUCCESS;
    }

    /**
     * Base query: rows that are still completely unmatched.
     */
    private function unmatched()
    {
        return DB::table('courses')
            ->whereNull('country_id')
            ->whereNull('state_prov_id')
            ->whereNull('city_id');
    }

    private function loadAmbiguousNames(): void
    {
        $this->ambiguousNames = DB::table('courses')
            ->whereNotNull('city_id')
            ->select('club_name', DB::raw('count(distinct city_id) dc'))
            ->groupBy('club_name')
            ->havingRaw('count(distinct city_id) > 1')
            ->pluck('club_name')
            ->flip()
            ->toArray();
    }

    /**
     * Tier 1: borrow location from already-located same-named courses,
     * but only when those siblings agree on a single location.
     */
    private function borrowFromSiblings(): void
    {
        $candidateNames = $this->unmatched()
            ->whereNotNull('club_name')
            ->distinct()
            ->pluck('club_name');

        foreach ($candidateNames as $name) {
            $locations = DB::table('courses')
                ->where('club_name', $name)
                ->whereNotNull('country_id')
                ->select('city_id', 'state_prov_id', 'country_id')
                ->distinct()
                ->get();

            // Must resolve to exactly one distinct (city, state, country) location.
            if ($locations->count() !== 1) {
                continue;
            }
            $loc = $locations->first();

            $updated = $this->unmatched()
                ->where('club_name', $name)
                ->update([
                    'city_id' => $loc->city_id,
                    'state_prov_id' => $loc->state_prov_id,
                    'country_id' => $loc->country_id,
                    'geo_source' => 'borrowed',
                    'geo_confidence' => 1.000,
                    'needs_review' => false,
                ]);

            if ($updated > 0) {
                $this->stats['borrowed'] += $updated;
            }
        }
    }

    private function resolveName(string $clubName, float $threshold): void
    {
        // Names reused across multiple cities can't be disambiguated from the club
        // name alone — skip straight to the (more specific) course_name fallback.
        if (! isset($this->ambiguousNames[$clubName])) {
            $result = $this->geocode($clubName);
            if ($result['status'] === 'accepted') {
                $this->applyAccepted($clubName, $result, $threshold, null, $this->isSuspect($clubName));

                return;
            }
        }

        // Fallback: try each distinct course_name under this club (rescues
        // placeholder club names where the course_name is the real place).
        $courseNames = $this->unmatched()
            ->where('club_name', $clubName)
            ->whereNotNull('course_name')
            ->distinct()
            ->pluck('course_name');

        foreach ($courseNames as $courseName) {
            $alt = $this->geocode($courseName);
            if ($alt['status'] === 'accepted') {
                $this->applyAccepted($clubName, $alt, $threshold, $courseName, $this->isSuspect($courseName));
            }
        }

        // Anything still unmatched under this club → flag for review (no ID writes).
        $this->flagRemaining($clubName);
    }

    /**
     * Write an accepted geocode result to the still-unmatched rows for a club_name
     * (optionally scoped to one course_name). Re-asserts the unmatched filter.
     */
    private function applyAccepted(string $clubName, array $result, float $threshold, ?string $courseName = null, bool $suspect = false): void
    {
        $city = $this->geo->nearestCity($result['lat'], $result['lng']);
        if ($city === null) {
            return; // leave for flagRemaining()
        }

        $query = $this->unmatched()->where('club_name', $clubName);
        if ($courseName !== null) {
            $query->where('course_name', $courseName);
        }

        $updated = $query->update([
            'city_id' => $city->km <= $threshold ? $city->id : null,
            'state_prov_id' => $city->state_id,
            'country_id' => $city->country_id,
            'geo_source' => 'geocoded_name',
            'geo_confidence' => round((float) $result['confidence'], 3),
            // Digit-prefixed / mangled source names can match a same-named town;
            // keep the location but flag it so the API can exclude if desired.
            'needs_review' => $suspect,
        ]);

        $this->stats['geocoded_rows'] += $updated;
        if ($suspect) {
            $this->stats['flagged_rows'] += $updated;
        }
    }

    private function flagRemaining(string $clubName): void
    {
        $flagged = $this->unmatched()
            ->where('club_name', $clubName)
            ->update(['needs_review' => true]);

        $this->stats['flagged_rows'] += $flagged;
    }

    /**
     * Geocode a single query string, using the cache when possible.
     *
     * @return array{status:string,lat:?float,lng:?float,confidence:?float}
     */
    private function geocode(string $query): array
    {
        $query = $this->cleanName($query);
        if ($query === '') {
            return ['status' => 'not_found', 'lat' => null, 'lng' => null, 'confidence' => null];
        }

        $cached = DB::table('geocode_cache')->where('query', $query)->first();
        if ($cached !== null) {
            return [
                'status' => $cached->status,
                'lat' => $cached->resolved_lat !== null ? (float) $cached->resolved_lat : null,
                'lng' => $cached->resolved_lng !== null ? (float) $cached->resolved_lng : null,
                'confidence' => $cached->confidence !== null ? (float) $cached->confidence : null,
            ];
        }

        $candidates = $this->fetchCandidates($query);
        $decision = $this->decide($query, $candidates);

        DB::table('geocode_cache')->insert([
            'query' => $query,
            'provider' => (string) $this->option('provider'),
            'response_json' => json_encode($candidates),
            'resolved_lat' => $decision['lat'],
            'resolved_lng' => $decision['lng'],
            'status' => $decision['status'],
            'confidence' => $decision['confidence'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Respect the provider's rate limit (only after a live call).
        usleep(((int) $this->option('sleep')) * 1000);

        return $decision;
    }

    /**
     * Call Nominatim and return normalized candidates.
     *
     * @return list<array{lat:float,lng:float,importance:float,country:?string}>
     */
    private function fetchCandidates(string $query): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'coursesApi-geocoder/1.0 (course location backfill)',
            ])->timeout(30)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => 5,
            ]);

            if ($response->failed()) {
                return [];
            }

            $out = [];
            foreach ($response->json() ?? [] as $r) {
                if (! isset($r['lat'], $r['lon'])) {
                    continue;
                }
                $out[] = [
                    'lat' => (float) $r['lat'],
                    'lng' => (float) $r['lon'],
                    'importance' => (float) ($r['importance'] ?? 0),
                    'country' => $r['address']['country_code'] ?? ($r['address']['country'] ?? null),
                    'name' => $r['name'] ?? '',
                    'display' => $r['display_name'] ?? '',
                    'category' => $r['category'] ?? ($r['class'] ?? ''),
                    'type' => $r['type'] ?? '',
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Decide accept / ambiguous / no_match / not_found.
     *
     * Correctness gate is NAME MATCH — the returned place must share a distinctive
     * word with the query (Nominatim `importance` does not track correctness for
     * these niche names). A golf-specific category is also accepted.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return array{status:string,lat:?float,lng:?float,confidence:?float}
     */
    private function decide(string $query, array $candidates): array
    {
        if ($candidates === []) {
            return ['status' => 'not_found', 'lat' => null, 'lng' => null, 'confidence' => null];
        }

        // Keep only candidates whose returned name/place matches the query.
        $matched = array_values(array_filter(
            $candidates,
            fn ($c) => $this->nameMatches($query, $c)
        ));

        if ($matched === []) {
            return ['status' => 'no_match', 'lat' => null, 'lng' => null, 'confidence' => null];
        }

        usort($matched, fn ($a, $b) => $b['importance'] <=> $a['importance']);
        $top = $matched[0];

        // Ambiguous if a matched rival sits in a DIFFERENT country (can't tell which).
        foreach (array_slice($matched, 1) as $c) {
            if ($c['country'] !== null && $top['country'] !== null && $c['country'] !== $top['country']) {
                return ['status' => 'ambiguous', 'lat' => null, 'lng' => null, 'confidence' => null];
            }
        }

        return [
            'status' => 'accepted',
            'lat' => $top['lat'],
            'lng' => $top['lng'],
            'confidence' => $top['importance'],
        ];
    }

    /**
     * A candidate matches if the returned place shares a distinctive (≥4-char)
     * word with the query, or Nominatim classified it as a golf course.
     *
     * @param  array<string,mixed>  $candidate
     */
    /**
     * Strip leading digit artifacts (e.g. "120Los Lagartos" -> "Los Lagartos")
     * and normalize whitespace before geocoding.
     */
    private function cleanName(string $name): string
    {
        $name = preg_replace('/^\s*\d+\s*/', '', trim($name)) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * A source name is "suspect" if it began with a digit artifact — those match
     * a same-named town too easily, so their result is kept but flagged.
     */
    private function isSuspect(string $original): bool
    {
        return (bool) preg_match('/^\s*\d/', $original);
    }

    /**
     * A candidate matches if the returned place shares a distinctive (≥4-char)
     * word with the query, or Nominatim classified it as a golf course.
     *
     * @param  array<string,mixed>  $candidate
     */
    private function nameMatches(string $query, array $candidate): bool
    {
        if (($candidate['type'] ?? '') === 'golf_course' || ($candidate['category'] ?? '') === 'golf') {
            return true;
        }

        $qTokens = $this->distinctiveTokens($query);
        if ($qTokens === []) {
            return false;
        }

        $place = trim(($candidate['name'] ?? '').' '.($candidate['display'] ?? ''));
        $pTokens = $this->distinctiveTokens($place);

        foreach (array_intersect($qTokens, $pTokens) as $shared) {
            if (strlen($shared) >= 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercased, de-accented, generic-golf-word-stripped tokens (len ≥ 3).
     *
     * @return list<string>
     */
    private function distinctiveTokens(string $s): array
    {
        $s = strtolower($s);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
        $parts = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $generic = ['golf', 'club', 'country', 'course', 'resort', 'links', 'and', 'the',
            'de', 'la', 'el', 'du', 'des', 'gc', 'cc'];

        $out = [];
        foreach ($parts as $p) {
            if (strlen($p) < 3 || in_array($p, $generic, true)) {
                continue;
            }
            $out[$p] = true;
        }

        return array_keys($out);
    }

    private function report(): void
    {
        $this->info('Geocoding complete.');
        $this->table(
            ['distinct names', 'borrowed rows', 'geocoded rows', 'flagged for review'],
            [[
                $this->stats['names'],
                $this->stats['borrowed'],
                $this->stats['geocoded_rows'],
                $this->stats['flagged_rows'],
            ]]
        );
    }
}
