<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resolves a coordinate to the nearest dr5hn city (and its state/country),
 * using a widening bounding box + haversine distance. Shared by the geo
 * linking and name-geocoding commands so every geo id stays consistent.
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
            $city = DB::table('cities')
                ->whereBetween('latitude', [$lat - $half, $lat + $half])
                ->whereBetween('longitude', [$lng - $half, $lng + $half])
                ->selectRaw(
                    'id, state_id, country_id, country_code, '.
                    '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) '.
                    '+ sin(radians(?)) * sin(radians(latitude))))) AS km',
                    [$lat, $lng, $lat]
                )
                ->orderBy('km')
                ->first();

            if ($city !== null) {
                return $city;
            }
        }

        return null;
    }
}
