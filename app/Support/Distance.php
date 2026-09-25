<?php

namespace App\Support;

/**
 * Units for the near-me radius search.
 *
 * The product API takes and returns **miles** by default — its audience is
 * overwhelmingly US golfers — and switches to kilometres on `units=km`. The
 * query itself is always kilometres (Course::scopeNear runs a haversine with
 * an earth radius of 6371 km), so this is the one place that converts between
 * the caller's unit and the database's.
 */
class Distance
{
    public const MILES_PER_KM = 0.621371;

    public const MI = 'mi';

    public const KM = 'km';

    /** Accepted `units` values. The first is the default. */
    public const UNITS = [self::MI, self::KM];

    /**
     * Normalise a caller-supplied `units` value, falling back to the default.
     * Anything invalid is still rejected by validation; this just keeps the
     * surrounding code from having to care about the bad case.
     */
    public static function unit(mixed $raw): string
    {
        return in_array($raw, self::UNITS, true) ? (string) $raw : self::MI;
    }

    /**
     * The largest radius accepted, expressed in the given unit. The kilometre
     * cap is rounded up so the full mile cap is actually reachable.
     */
    public static function maxRadius(string $unit): float
    {
        $maxMi = (float) config('api.max_radius_mi', 100);

        return $unit === self::KM ? ceil($maxMi / self::MILES_PER_KM) : $maxMi;
    }

    /** A caller's radius, in the kilometres scopeNear expects. */
    public static function toKm(float $value, string $unit): float
    {
        return $unit === self::KM ? $value : $value / self::MILES_PER_KM;
    }

    /** A kilometre distance from the query, in the caller's unit. */
    public static function fromKm(float $km, string $unit): float
    {
        return $unit === self::KM ? $km : $km * self::MILES_PER_KM;
    }
}
