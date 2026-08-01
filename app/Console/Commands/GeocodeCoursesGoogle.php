<?php

namespace App\Console\Commands;

use App\Support\GeoResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GeocodeCoursesGoogle extends Command
{
    /**
     * @var string
     */
    protected $signature = 'courses:geocode-google
        {--sleep=200 : Milliseconds between live API calls}
        {--limit=0 : Cap the number of distinct names resolved (0 = all)}
        {--threshold=50 : Max km to accept a city_id match}';

    /**
     * @var string
     */
    protected $description = 'Resolve remaining name-only courses via Google Places Text Search; fills address + city/state/country.';

    private GeoResolver $geo;

    private string $key;

    private array $stats = [
        'names' => 0,
        'resolved_rows' => 0,
        'flagged_rows' => 0,
        'not_found' => 0,
    ];

    public function handle(): int
    {
        $this->key = (string) config('services.google.places_key');
        if ($this->key === '') {
            $this->error('Missing GOOGLE_MAPS_API_KEY (config services.google.places_key). Add it to .env first.');

            return self::FAILURE;
        }

        $this->geo = new GeoResolver;
        $threshold = (float) $this->option('threshold');
        $limit = (int) $this->option('limit');

        DB::disableQueryLog();

        $names = $this->unmatched()
            ->whereNotNull('club_name')->where('club_name', '!=', '')
            ->distinct()->orderBy('club_name')->pluck('club_name');

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
        $this->info('Google geocoding complete.');
        $this->table(
            ['distinct names', 'resolved rows', 'flagged rows', 'not found names'],
            [[$this->stats['names'], $this->stats['resolved_rows'], $this->stats['flagged_rows'], $this->stats['not_found']]]
        );

        return self::SUCCESS;
    }

    private function unmatched()
    {
        return DB::table('courses')
            ->whereNull('country_id')->whereNull('state_prov_id')->whereNull('city_id');
    }

    private function resolveName(string $clubName, float $threshold): void
    {
        $query = $this->cleanName($clubName);
        $place = $query === '' ? null : $this->placeSearch($query);

        if ($place === null) {
            $this->stats['not_found']++;
            $this->unmatched()->where('club_name', $clubName)->update(['needs_review' => true]);

            return;
        }

        $city = $this->geo->nearestCity($place['lat'], $place['lng']);
        if ($city === null) {
            $this->unmatched()->where('club_name', $clubName)->update(['needs_review' => true]);

            return;
        }

        // Reasonable confidence when the returned place is a golf course or its
        // name shares a distinctive word with the query. Digit-prefixed source
        // names are kept but always flagged (they match a same-named place easily).
        $good = ($place['is_golf'] || $this->nameMatches($query, $place['name'].' '.$place['address']))
            && ! $this->isSuspect($clubName);

        $updated = $this->unmatched()->where('club_name', $clubName)->update([
            'address' => $place['address'],
            'city_id' => $city->km <= $threshold ? $city->id : null,
            'state_prov_id' => $city->state_id,
            'country_id' => $city->country_id,
            'geo_source' => 'google',
            'geo_confidence' => $good ? 0.900 : 0.500,
            'needs_review' => ! $good,
        ]);

        $this->stats['resolved_rows'] += $updated;
        if (! $good) {
            $this->stats['flagged_rows'] += $updated;
        }
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/^\s*\d+\s*/', '', trim($name)) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function isSuspect(string $original): bool
    {
        return (bool) preg_match('/^\s*\d/', $original);
    }

    /**
     * Google Places Text Search, cached in geocode_cache (provider=google).
     *
     * @return array{lat:float,lng:float,address:string,name:string,is_golf:bool}|null
     */
    private function placeSearch(string $query): ?array
    {
        $cacheKey = 'google:'.$query;
        $cached = DB::table('geocode_cache')->where('query', $cacheKey)->first();
        if ($cached !== null) {
            if ($cached->status !== 'accepted' || $cached->response_json === null) {
                return null;
            }
            $d = json_decode($cached->response_json, true);

            return [
                'lat' => (float) $cached->resolved_lat,
                'lng' => (float) $cached->resolved_lng,
                'address' => $d['address'] ?? '',
                'name' => $d['name'] ?? '',
                'is_golf' => (bool) ($d['is_golf'] ?? false),
            ];
        }

        $result = $this->fetch($query);

        DB::table('geocode_cache')->insert([
            'query' => $cacheKey,
            'provider' => 'google',
            'response_json' => $result !== null ? json_encode($result) : null,
            'resolved_lat' => $result['lat'] ?? null,
            'resolved_lng' => $result['lng'] ?? null,
            'status' => $result !== null ? 'accepted' : 'not_found',
            'confidence' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        usleep(((int) $this->option('sleep')) * 1000);

        return $result;
    }

    /**
     * @return array{lat:float,lng:float,address:string,name:string,is_golf:bool}|null
     */
    private function fetch(string $query): ?array
    {
        try {
            $response = Http::timeout(30)->get('https://maps.googleapis.com/maps/api/place/textsearch/json', [
                'query' => $query,
                'key' => $this->key,
            ]);

            $body = $response->json();
            $status = $body['status'] ?? 'UNKNOWN';

            if ($status === 'REQUEST_DENIED' || $status === 'INVALID_REQUEST') {
                $this->newLine();
                $this->error('Google API error: '.$status.' — '.($body['error_message'] ?? ''));
                throw new \RuntimeException('Google API '.$status);
            }

            if ($status !== 'OK' || empty($body['results'][0])) {
                return null;
            }

            $r = $body['results'][0];
            $loc = $r['geometry']['location'] ?? null;
            if ($loc === null) {
                return null;
            }

            return [
                'lat' => (float) $loc['lat'],
                'lng' => (float) $loc['lng'],
                'address' => $r['formatted_address'] ?? '',
                'name' => $r['name'] ?? '',
                'is_golf' => in_array('golf_course', $r['types'] ?? [], true),
            ];
        } catch (\RuntimeException $e) {
            throw $e; // key/config errors should stop the run
        } catch (\Throwable) {
            return null;
        }
    }

    private function nameMatches(string $query, string $place): bool
    {
        $q = $this->tokens($query);
        $p = $this->tokens($place);
        foreach (array_intersect($q, $p) as $shared) {
            if (strlen($shared) >= 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $s): array
    {
        $s = strtolower($s);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
        $parts = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $generic = ['golf', 'club', 'country', 'course', 'resort', 'links', 'and', 'the', 'de', 'la', 'el', 'gc', 'cc'];
        $out = [];
        foreach ($parts as $p) {
            if (strlen($p) >= 3 && ! in_array($p, $generic, true)) {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }
}
