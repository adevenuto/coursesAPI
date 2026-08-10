<?php

namespace App\Support;

/**
 * Digs a usable coordinate out of a course's layout_data.
 *
 * 964 courses carry no address and no lat/lng of their own but do have green
 * centers or hole positions buried in layout_data — real surveyed geometry that
 * nothing has ever used for geocoding.
 */
class LayoutCoordinates
{
    /**
     * First sane [lat, lng] pair anywhere in the structure.
     *
     * @param  mixed  $data
     * @return array{0:float,1:float}|null
     */
    public static function find($data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['lat'], $data['lng']) && is_numeric($data['lat']) && is_numeric($data['lng'])) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];

            // Reject null-island and out-of-range junk.
            if (abs($lat) > 0.0001 && abs($lat) <= 90 && abs($lng) > 0.0001 && abs($lng) <= 180) {
                return [$lat, $lng];
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = self::find($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** Same, from a raw JSON string. */
    public static function fromJson(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        return self::find(json_decode($json, true));
    }
}
