<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a coordinate to the nearest dr5hn city (and its state/country),
 * using a widening bounding box + haversine distance. Shared by the geo
 * linking and name-geocoding commands so every geo id stays consistent.
 *
 * fromAddressComponents() is the editor's path: Google's address components
 * are authoritative for country and state, so they constrain the lookup and
 * the coordinate only picks a city *within* that constraint. That stops the
 * nearest-city guess from landing on the wrong side of a border.
 */
class GeoResolver
{
    /**
     * Find the nearest city to a coordinate.
     *
     * @return object{id:int,state_id:int,country_id:int,country_code:?string,km:float}|null
     */
    public function nearestCity(float $lat, float $lng, float $box = 0.6): ?object
    {
        foreach ([$box, $box * 3, $box * 8] as $half) {
            $city = $this->cityQuery($lat, $lng)
                ->whereBetween('latitude', [$lat - $half, $lat + $half])
                ->whereBetween('longitude', [$lng - $half, $lng + $half])
                ->first();

            if ($city !== null) {
                return $city;
            }
        }

        return null;
    }

    /**
     * Resolve the city/state/country trio from Google Places address components,
     * falling back to the coordinate wherever a component can't be matched.
     *
     * $maxCityKm gates only the *fallback* city (the nearest one in the resolved
     * state), not an exact name match — a threshold guards a guess, not a match.
     * Null, the default, accepts any distance: the editor deliberately always
     * takes the nearest in-state city.
     *
     * @param  array{country_code?:?string,country_name?:?string,state_code?:?string,state_name?:?string,city_candidates?:array<int,string>}  $parts
     * @return object{id:?int,state_id:?int,country_id:?int}|null
     */
    public function fromAddressComponents(array $parts, float $lat, float $lng, ?float $maxCityKm = null): ?object
    {
        $country = $this->matchCountry($parts['country_code'] ?? null, $parts['country_name'] ?? null);

        if ($country === null) {
            return $this->trio($this->nearestCity($lat, $lng));
        }

        $stateId = $this->matchState(
            $country,
            $parts['state_code'] ?? null,
            $parts['state_name'] ?? null,
        );

        if ($stateId === null) {
            // Country known, state not: keep the country and let the coordinate
            // pick inside it rather than anywhere on earth.
            $city = $this->nearestCityInCountry((int) $country->id, $lat, $lng);

            return $this->trio($city) ?? (object) ['id' => null, 'state_id' => null, 'country_id' => (int) $country->id];
        }

        $city = $this->matchCityInState($stateId, $parts['city_candidates'] ?? [], $lat, $lng);

        if ($city === null) {
            $city = $this->nearestCityInState($stateId, $lat, $lng);

            // Too far to be this course's city (rural areas the dataset thins out
            // over). Keep the state and country, which are still right.
            if ($city !== null && $maxCityKm !== null && (float) $city->km > $maxCityKm) {
                $city = null;
            }
        }

        // Read the trio off the chosen city row so the three ids are always
        // internally consistent. A state with no cities keeps state + country.
        return $this->trio($city) ?? (object) [
            'id' => null,
            'state_id' => $stateId,
            'country_id' => (int) $country->id,
        ];
    }

    /** Match Google's country component. Its short_name is ISO 3166-1 alpha-2. */
    private function matchCountry(?string $code, ?string $name): ?object
    {
        if (($code = trim((string) $code)) !== '') {
            $country = DB::table('countries')->select('id', 'iso2')
                ->whereRaw('upper(iso2) = ?', [strtoupper($code)])
                ->first();

            if ($country !== null) {
                return $country;
            }
        }

        if (($name = trim((string) $name)) === '') {
            return null;
        }

        return DB::table('countries')->select('id', 'iso2')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * Match Google's administrative_area_level_1 within a country. Its short_name
     * is the subdivision code where one exists (US/CA/AU), otherwise a repeat of
     * the long name — so both are tried against both columns.
     */
    private function matchState(object $country, ?string $code, ?string $name): ?int
    {
        $code = trim((string) $code);
        $name = trim((string) $name);
        $iso2 = trim((string) ($country->iso2 ?? ''));

        $attempts = [];
        if ($code !== '') {
            $attempts[] = ['iso2', mb_strtolower($code)];
            if ($iso2 !== '') {
                $attempts[] = ['iso3166_2', mb_strtolower($iso2.'-'.$code)];
            }
        }
        if ($name !== '') {
            $attempts[] = ['name', mb_strtolower($name)];
        }
        if ($code !== '') {
            $attempts[] = ['name', mb_strtolower($code)];
        }

        foreach ($attempts as [$column, $value]) {
            $id = DB::table('states')
                ->where('country_id', $country->id)
                ->whereRaw("lower({$column}) = ?", [$value])
                ->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Exact-name match for any of Google's locality candidates inside a state.
     * Duplicated names within one state are broken by distance.
     *
     * @param  array<int, string>  $candidates
     */
    private function matchCityInState(int $stateId, array $candidates, float $lat, float $lng): ?object
    {
        foreach ($candidates as $candidate) {
            if (($candidate = trim((string) $candidate)) === '') {
                continue;
            }

            $city = $this->cityQuery($lat, $lng)
                ->where('state_id', $stateId)
                ->whereRaw('lower(name) = ?', [mb_strtolower($candidate)])
                ->first();

            if ($city !== null) {
                return $city;
            }
        }

        return null;
    }

    /**
     * Nearest city inside one state. Google routinely returns a neighborhood or
     * a town the dr5hn dataset doesn't carry, and a wrong-but-adjacent city beats
     * no city at all as long as it sits in the right state.
     */
    private function nearestCityInState(int $stateId, float $lat, float $lng): ?object
    {
        return $this->cityQuery($lat, $lng)->where('state_id', $stateId)->first();
    }

    /** Nearest city inside one country, widening the box so the scan stays indexed. */
    private function nearestCityInCountry(int $countryId, float $lat, float $lng, float $box = 0.6): ?object
    {
        foreach ([$box, $box * 3, $box * 8] as $half) {
            $city = $this->cityQuery($lat, $lng)
                ->where('country_id', $countryId)
                ->whereBetween('latitude', [$lat - $half, $lat + $half])
                ->whereBetween('longitude', [$lng - $half, $lng + $half])
                ->first();

            if ($city !== null) {
                return $city;
            }
        }

        // Nothing nearby in this country (a small island, a bad coordinate):
        // take the closest city it does have rather than crossing the border.
        return $this->cityQuery($lat, $lng)->where('country_id', $countryId)->first();
    }

    /** Cities ordered by haversine distance from a coordinate. */
    private function cityQuery(float $lat, float $lng): Builder
    {
        return DB::table('cities')
            ->selectRaw(
                'id, state_id, country_id, country_code, '.
                '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) '.
                '+ sin(radians(?)) * sin(radians(latitude))))) AS km',
                [$lat, $lng, $lat]
            )
            ->orderBy('km');
    }

    /** @return object{id:?int,state_id:?int,country_id:?int}|null */
    private function trio(?object $city): ?object
    {
        return $city === null ? null : (object) [
            'id' => (int) $city->id,
            'state_id' => (int) $city->state_id,
            'country_id' => (int) $city->country_id,
        ];
    }
}
