<?php

namespace App\Console\Commands;

use App\Support\GeoResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkCoursesGeo extends Command
{
    /**
     * @var string
     */
    protected $signature = 'courses:link-geo
        {--chunk=500 : Courses processed per batch}
        {--threshold=50 : Max distance (km) to accept a city_id match}
        {--box=0.6 : Initial bounding-box half-size in degrees}';

    /**
     * @var string
     */
    protected $description = 'Backfill courses.city_id/state_prov_id/country_id/postal_code by matching against the geo tables.';

    /** token (lowercased) => country id */
    private array $countryMap = [];

    private GeoResolver $geo;

    /** Running counters. */
    private array $stats = [
        'processed' => 0,
        'city' => 0,
        'state_country' => 0,
        'from_layout' => 0,
        'country_only' => 0,
        'postal' => 0,
        'unmatched' => 0,
    ];

    public function handle(): int
    {
        $this->geo = new GeoResolver;
        $this->buildCountryMap();

        $threshold = (float) $this->option('threshold');
        $box = (float) $this->option('box');
        $chunk = max(1, (int) $this->option('chunk'));

        DB::disableQueryLog();

        DB::table('courses')
            ->select('id', 'lat', 'lng', 'address')
            // Never touch rows resolved by external geocoding / borrow.
            ->where(function ($q) {
                $q->whereNull('geo_source')
                    ->orWhereNotIn('geo_source', ['geocoded_name', 'borrowed']);
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($courses) use ($threshold, $box) {
                DB::transaction(function () use ($courses, $threshold, $box) {
                    foreach ($courses as $course) {
                        $this->linkCourse($course, $threshold, $box);
                    }
                });
                $this->output->write("\rProcessed: {$this->stats['processed']}");
            });

        $this->newLine();
        $this->info('Done.');
        $this->table(
            ['processed', 'city_id set', 'state+country set', 'via layout_data', 'country-only (fallback)', 'postal set', 'unmatched'],
            [[
                $this->stats['processed'],
                $this->stats['city'],
                $this->stats['state_country'],
                $this->stats['from_layout'],
                $this->stats['country_only'],
                $this->stats['postal'],
                $this->stats['unmatched'],
            ]]
        );

        return self::SUCCESS;
    }

    private function linkCourse(object $course, float $threshold, float $box): void
    {
        $this->stats['processed']++;

        $cityId = null;
        $stateId = null;
        $countryId = null;
        $countryCode = null;

        $lat = $course->lat !== null ? (float) $course->lat : null;
        $lng = $course->lng !== null ? (float) $course->lng : null;

        // Fallback coordinate source: hole-level lat/lng embedded in layout_data.
        $fromLayout = false;
        if (($lat === null || $lng === null)) {
            $coord = $this->coordFromLayout(
                DB::table('courses')->where('id', $course->id)->value('layout_data')
            );
            if ($coord !== null) {
                [$lat, $lng] = $coord;
                $fromLayout = true;
            }
        }

        if ($lat !== null && $lng !== null) {
            $city = $this->geo->nearestCity($lat, $lng, $box);
            if ($city !== null) {
                $stateId = $city->state_id;
                $countryId = $city->country_id;
                $countryCode = $city->country_code;
                if ($city->km <= $threshold) {
                    $cityId = $city->id;
                }
                $this->stats['state_country']++;
                if ($cityId !== null) {
                    $this->stats['city']++;
                }
                if ($fromLayout) {
                    $this->stats['from_layout']++;
                }
            }
        }

        // Fallback: derive country from the address when geo gave us nothing.
        if ($countryId === null) {
            $countryId = $this->countryFromStreet($course->address);
            if ($countryId !== null) {
                $countryCode = $this->countryCodeById($countryId);
                $this->stats['country_only']++;
            }
        }

        $postal = $this->parsePostal($course->address, $countryCode);
        if ($postal !== null) {
            $this->stats['postal']++;
        }

        if ($cityId === null && $stateId === null && $countryId === null && $postal === null) {
            $this->stats['unmatched']++;

            return;
        }

        DB::table('courses')->where('id', $course->id)->update([
            'city_id' => $cityId,
            'state_prov_id' => $stateId,
            'country_id' => $countryId,
            'postal_code' => $postal,
        ]);
    }

    /**
     * Recursively find the first sane [lat, lng] pair embedded in layout_data.
     *
     * @return array{0:float,1:float}|null
     */
    private function coordFromLayout(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $this->searchCoord($data) : null;
    }

    /**
     * @param  array<mixed>  $data
     * @return array{0:float,1:float}|null
     */
    private function searchCoord(array $data): ?array
    {
        if (isset($data['lat'], $data['lng']) && is_numeric($data['lat']) && is_numeric($data['lng'])) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];
            if (abs($lat) > 0.0001 && abs($lat) <= 90 && abs($lng) > 0.0001 && abs($lng) <= 180) {
                return [$lat, $lng];
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->searchCoord($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function buildCountryMap(): void
    {
        foreach (DB::table('countries')->get(['id', 'name', 'iso2', 'iso3']) as $c) {
            foreach ([$c->name, $c->iso2, $c->iso3] as $token) {
                $key = $this->normalizeToken($token);
                if ($key !== '' && ! isset($this->countryMap[$key])) {
                    $this->countryMap[$key] = $c->id;
                }
            }
        }

        // Common address tokens that are not the canonical name/iso codes.
        $aliases = [
            'usa' => 'US', 'us' => 'US', 'united states of america' => 'US', 'u.s.a.' => 'US', 'u.s.' => 'US',
            'uk' => 'GB', 'u.k.' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB',
            'northern ireland' => 'GB', 'great britain' => 'GB',
            'uae' => 'AE', 'south korea' => 'KR', 'north korea' => 'KP', 'russia' => 'RU',
            'the netherlands' => 'NL', 'holland' => 'NL', 'ivory coast' => 'CI', 'czech republic' => 'CZ',
        ];
        foreach ($aliases as $token => $iso2) {
            $id = $this->countryMap[$this->normalizeToken($iso2)] ?? null;
            if ($id !== null) {
                $this->countryMap[$token] = $id;
            }
        }
    }

    private function countryFromStreet(?string $street): ?int
    {
        if ($street === null || trim($street) === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $street));
        $last = end($parts);

        return $this->countryMap[$this->normalizeToken($last)] ?? null;
    }

    private function countryCodeById(int $id): ?string
    {
        return DB::table('countries')->where('id', $id)->value('iso2');
    }

    private function parsePostal(?string $street, ?string $countryCode): ?string
    {
        if ($street === null || trim($street) === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $street));
        $n = count($parts);
        $hay = $n >= 2 ? $parts[$n - 2] : $parts[0];
        $cc = strtoupper((string) $countryCode);

        $patterns = [
            'US' => '/\b(\d{5})(?:-\d{4})?\b/',
            'CA' => '/\b([A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d)\b/',
            'GB' => '/\b([A-Za-z]{1,2}\d[A-Za-z\d]?\s?\d[A-Za-z]{2})\b/',
        ];

        if (isset($patterns[$cc]) && preg_match($patterns[$cc], $hay, $m)) {
            return strtoupper(trim($m[1]));
        }

        // UK-style alphanumeric anywhere (also catches unmatched country codes).
        if (preg_match('/\b([A-Za-z]{1,2}\d[A-Za-z\d]?\s\d[A-Za-z]{2})\b/', $hay, $m)) {
            return strtoupper(trim($m[1]));
        }

        // Numeric postal (AU/EU/etc.) — 4 to 5 digits.
        if (preg_match('/\b(\d{4,5})\b/', $hay, $m)) {
            return $m[1];
        }

        return null;
    }

    private function normalizeToken(?string $token): string
    {
        return trim(strtolower((string) $token), " \t\n\r\0\x0B.");
    }
}
