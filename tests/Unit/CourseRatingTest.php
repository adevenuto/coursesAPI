<?php

namespace Tests\Unit;

use App\Support\CourseRating;
use PHPUnit\Framework\TestCase;

class CourseRatingTest extends TestCase
{
    public function test_a_nine_gets_the_lower_floor(): void
    {
        $this->assertSame(CourseRating::MIN_NINE, CourseRating::min(9));
        $this->assertSame(CourseRating::MIN_NINE, CourseRating::min(6));
    }

    public function test_anything_longer_keeps_the_original_floor(): void
    {
        $this->assertSame(CourseRating::MIN_FULL, CourseRating::min(18));
        $this->assertSame(CourseRating::MIN_FULL, CourseRating::min(27));
    }

    /**
     * An unknown hole count must not quietly loosen the bound.
     */
    public function test_an_unknown_hole_count_falls_back_to_the_stricter_floor(): void
    {
        $this->assertSame(CourseRating::MIN_FULL, CourseRating::min(0));
    }

    public function test_the_shortest_real_nines_clear_the_floor(): void
    {
        // Atkinson Country Club's par-3 nine, the lowest rating in the database.
        $this->assertGreaterThan(CourseRating::min(9), 23.5);
    }

    public function test_played_holes_ignores_empty_slots(): void
    {
        // How a good number of legacy nine-hole courses are actually stored:
        // eighteen slots with the back nine blank.
        $holes = [];
        for ($i = 1; $i <= 18; $i++) {
            $holes[] = $i <= 9
                ? ['hole' => $i, 'par' => 4, 'length' => 380]
                : ['hole' => $i, 'par' => null, 'length' => null];
        }

        $this->assertSame(9, CourseRating::playedHoles($holes));
        $this->assertSame(CourseRating::MIN_NINE, CourseRating::min(CourseRating::playedHoles($holes)));
    }

    public function test_played_holes_treats_a_blank_string_as_empty(): void
    {
        // The editor posts par and length as strings, so "" is the empty case.
        $this->assertSame(1, CourseRating::playedHoles([
            ['hole' => 1, 'par' => '4', 'length' => '380'],
            ['hole' => 2, 'par' => '', 'length' => ''],
        ]));
    }
}
