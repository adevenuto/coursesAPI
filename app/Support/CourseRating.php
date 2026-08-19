<?php

namespace App\Support;

/**
 * The storable bounds for a course rating.
 *
 * A course rating is a scratch player's expected score for the round, so it
 * tracks how many holes are played. The familiar 55–80 band is calibrated for
 * a full eighteen; a nine rates roughly half of that. Willow Hill's card, for
 * instance, prints 33.6 from the Blue tees — correct, and rejected outright by
 * a flat 55 floor. Nine-hole ratings in this database run 23.5 to 41, the
 * lowest being par-3 and executive nines.
 *
 * The bound exists to catch a misread digit (a dropped decimal, a slope landing
 * in the rating column), not to enforce golf orthodoxy, so it is deliberately
 * loose within each band.
 */
final class CourseRating
{
    public const MAX = 80.0;

    /** Floor for a nine or shorter. */
    public const MIN_NINE = 20.0;

    /** Floor for anything longer, unchanged from the original rule. */
    public const MIN_FULL = 55.0;

    public static function min(int $holes): float
    {
        return $holes > 0 && $holes <= 9 ? self::MIN_NINE : self::MIN_FULL;
    }

    /**
     * How many holes a tee actually plays.
     *
     * Counts holes carrying a par or a yardage rather than array length: a
     * fair number of legacy nine-hole courses store eighteen hole slots with
     * the back nine left empty, and those rate as a nine.
     *
     * @param  array<int, array<string, mixed>>  $holes
     */
    public static function playedHoles(array $holes): int
    {
        $played = 0;

        foreach ($holes as $hole) {
            $filled = false;
            foreach (['par', 'length', 'yards'] as $key) {
                if (($hole[$key] ?? null) !== null && $hole[$key] !== '') {
                    $filled = true;
                    break;
                }
            }
            if ($filled) {
                $played++;
            }
        }

        return $played;
    }
}
