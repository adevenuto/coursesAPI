<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Google Geocoding API reverse lookup: coordinate → address components.
 *
 * This is the server-side twin of what the editor's place picker does in the
 * browser, and it feeds the same GeoResolver::fromAddressComponents(). Asking
 * "what is the address of this point?" is unambiguous, unlike searching by a
 * course name that half a dozen states share.
 *
 * Every response is cached in geocode_cache keyed by the rounded coordinate, so
 * a run is idempotent, resumable, and can be replayed on another database with
 * no further API spend (see cacheOnly).
 */
class ReverseGeocoder
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** Google's locality shape varies by country; try these in order. */
    private const CITY_TYPES = [
        'locality', 'postal_town', 'administrative_area_level_2',
        'administrative_area_level_3', 'sublocality_level_1',
    ];

    public int $calls = 0;

    public int $cacheHits = 0;

    public int $cacheMisses = 0;

    public function __construct(
        private string $key = '',
        private bool $cacheOnly = false,
        private int $sleepMs = 100,
    ) {}

    /**
     * @return array{country_code:?string,country_name:?string,state_code:?string,state_name:?string,city_candidates:array<int,string>,formatted_address:?string,postal_code:?string}|null
     */
    public function lookup(float $lat, float $lng): ?array
    {
        $cacheKey = sprintf('revgeo:%.6f,%.6f', $lat, $lng);

        $cached = DB::table('geocode_cache')->where('query', $cacheKey)->first();
        if ($cached !== null) {
            $this->cacheHits++;

            return $cached->status === 'accepted' && $cached->response_json !== null
                ? json_decode($cached->response_json, true)
                : null;
        }

        // Replay mode: never reach out. A miss is reported, not silently skipped
        // — it means this database has a coordinate the cache was not built for.
        if ($this->cacheOnly) {
            $this->cacheMisses++;

            return null;
        }

        $parsed = $this->fetch($lat, $lng);
        $this->calls++;

        DB::table('geocode_cache')->insert([
            'query' => $cacheKey,
            'provider' => 'google-reverse',
            'response_json' => $parsed !== null ? json_encode($parsed) : null,
            'resolved_lat' => $lat,
            'resolved_lng' => $lng,
            'status' => $parsed !== null ? 'accepted' : 'not_found',
            'confidence' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }

        return $parsed;
    }

    /** @return array<string, mixed>|null */
    private function fetch(float $lat, float $lng): ?array
    {
        $response = Http::retry(2, 500)->timeout(15)->get(self::ENDPOINT, [
            'latlng' => $lat.','.$lng,
            'key' => $this->key,
        ]);

        if (! $response->ok()) {
            return null;
        }

        $body = $response->json();

        if (($body['status'] ?? '') === 'REQUEST_DENIED') {
            // A referer-restricted browser key, a disabled API, or billing off.
            // Fail loudly rather than cache thousands of empty results.
            throw new \RuntimeException('Google Geocoding rejected the key: '.($body['error_message'] ?? 'REQUEST_DENIED'));
        }

        $result = $body['results'][0] ?? null;

        return $result === null ? null : $this->parse($result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function parse(array $result): array
    {
        $components = $result['address_components'] ?? [];
        $pick = function (string $type) use ($components) {
            foreach ($components as $c) {
                if (in_array($type, $c['types'] ?? [], true)) {
                    return $c;
                }
            }

            return null;
        };

        $country = $pick('country');
        $state = $pick('administrative_area_level_1');

        $candidates = [];
        foreach (self::CITY_TYPES as $type) {
            $name = $pick($type)['long_name'] ?? null;
            if ($name !== null && $name !== '') {
                $candidates[] = $name;
            }
        }

        return [
            'country_code' => $country['short_name'] ?? null, // ISO 3166-1 alpha-2
            'country_name' => $country['long_name'] ?? null,
            'state_code' => $state['short_name'] ?? null,
            'state_name' => $state['long_name'] ?? null,
            'city_candidates' => $candidates,
            'formatted_address' => $result['formatted_address'] ?? null,
            'postal_code' => $pick('postal_code')['long_name'] ?? null,
        ];
    }
}
