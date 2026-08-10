<?php

namespace App\Console\Commands;

use App\Support\AddressComponents;
use App\Support\GeoResolver;
use App\Support\LayoutCoordinates;
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

    private AddressComponents $addresses;

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
        $this->addresses = new AddressComponents;

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
            $coord = LayoutCoordinates::fromJson(
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

    private function countryFromStreet(?string $street): ?int
    {
        return $this->addresses->countryIdFromTail($street);
    }

    private function countryCodeById(int $id): ?string
    {
        return $this->addresses->iso2For($id);
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
}
