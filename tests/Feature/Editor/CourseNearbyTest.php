<?php

namespace Tests\Feature\Editor;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseNearbyTest extends TestCase
{
    use RefreshDatabase;

    /** Cantigny, Wheaton IL — the reference point for every distance here. */
    private const LAT = 41.8472222;

    private const LNG = -88.1552778;

    private function editor(): User
    {
        return User::factory()->create(['plan' => 'pro', 'role' => 'editor']);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function course(array $attrs = []): Course
    {
        return Course::create(array_merge([
            'course_name' => 'Course',
            'club_name' => 'A Club',
            'lat' => self::LAT,
            'lng' => self::LNG,
        ], $attrs));
    }

    /**
     * A course roughly $miles north of the reference point. One degree of
     * latitude is ~69 miles, and latitude spacing is unaffected by longitude,
     * so this is accurate enough to sit either side of the 5-mile radius.
     */
    private function courseMilesAway(float $miles, array $attrs = []): Course
    {
        return $this->course(array_merge(['lat' => self::LAT + ($miles / 69.0)], $attrs));
    }

    private function nearby(Course $course, ?User $as = null): array
    {
        $page = $this->actingAs($as ?? $this->editor())
            ->get("/courses/{$course->id}/edit")
            ->assertOk();

        return $page->viewData('page')['props']['nearby'];
    }

    public function test_courses_within_the_radius_are_listed_nearest_first(): void
    {
        $subject = $this->course(['course_name' => 'Subject', 'club_name' => 'Subject Club']);
        $far = $this->courseMilesAway(4, ['course_name' => 'Four Miles', 'club_name' => 'Far Club']);
        $near = $this->courseMilesAway(1, ['course_name' => 'One Mile', 'club_name' => 'Near Club']);

        $nearby = $this->nearby($subject);

        $this->assertSame(5.0, $nearby['radius_mi']);
        $this->assertNull($nearby['placeholder']);
        $this->assertSame([$near->id, $far->id], array_column($nearby['courses'], 'id'));
        $this->assertEqualsWithDelta(1.0, $nearby['courses'][0]['distance_mi'], 0.15);
        $this->assertEqualsWithDelta(4.0, $nearby['courses'][1]['distance_mi'], 0.15);
    }

    public function test_courses_beyond_the_radius_are_excluded(): void
    {
        $subject = $this->course(['course_name' => 'Subject', 'club_name' => 'Subject Club']);
        $this->courseMilesAway(10, ['course_name' => 'Ten Miles', 'club_name' => 'Distant Club']);

        $this->assertSame([], $this->nearby($subject)['courses']);
    }

    public function test_same_club_courses_are_pinned_above_closer_neighbours(): void
    {
        // Cantigny-like: three routings of one facility on identical coordinates,
        // plus an unrelated course a fraction of a mile away. The sibling rows
        // must still lead, which is what preserves the old grouping signal.
        $subject = $this->course(['course_name' => 'Woodside/Hillside', 'club_name' => 'Cantigny Golf']);
        $siblingB = $this->course(['course_name' => 'Lakeside/Hillside', 'club_name' => 'Cantigny Golf']);
        $siblingC = $this->course(['course_name' => 'Woodside/Lakeside', 'club_name' => 'Cantigny Golf']);
        $neighbour = $this->courseMilesAway(0.2, ['course_name' => 'Next Door', 'club_name' => 'Other Club']);

        $courses = $this->nearby($subject)['courses'];

        $this->assertSame(
            [$siblingB->id, $siblingC->id, $neighbour->id],
            array_column($courses, 'id'),
        );
        $this->assertTrue($courses[0]['same_club']);
        $this->assertTrue($courses[1]['same_club']);
        $this->assertFalse($courses[2]['same_club']);
        $this->assertSame(0.0, $courses[0]['distance_mi']);
    }

    public function test_the_list_is_capped(): void
    {
        $subject = $this->course(['course_name' => 'Subject', 'club_name' => 'Subject Club']);

        // 20 neighbours, all distinct clubs so the placeholder guard stays quiet
        // and they spread over distance rather than stacking on one coordinate.
        foreach (range(1, 20) as $i) {
            $this->courseMilesAway($i * 0.2, ['course_name' => "Neighbour {$i}", 'club_name' => "Club {$i}"]);
        }

        $this->assertCount(12, $this->nearby($subject)['courses']);
    }

    public function test_a_shared_placeholder_coordinate_is_reported_instead_of_listed(): void
    {
        // The real failure this guards: one Australian centroid holds 87 courses
        // from 81 unrelated clubs. Listing those as neighbours is noise.
        $subject = $this->course(['course_name' => 'Roebourne', 'club_name' => 'Roebourne Golf Club']);
        foreach (range(1, 6) as $i) {
            $this->course(['course_name' => "Unrelated {$i}", 'club_name' => "Unrelated Club {$i}"]);
        }

        $nearby = $this->nearby($subject);

        $this->assertSame(['courses' => 6, 'clubs' => 6], $nearby['placeholder']);
        $this->assertSame([], $nearby['courses'], 'a placeholder coordinate has no meaningful neighbours');
    }

    public function test_a_genuine_multi_course_facility_is_not_mistaken_for_a_placeholder(): void
    {
        // Haig-Point-shaped: 14 courses on one coordinate, all one club. Must not
        // trip the guard, however many rows there are.
        $subject = $this->course(['course_name' => 'No. 1', 'club_name' => 'Pinehurst Cc']);
        foreach (range(2, 14) as $i) {
            $this->course(['course_name' => "No. {$i}", 'club_name' => 'Pinehurst Cc']);
        }

        $nearby = $this->nearby($subject);

        $this->assertNull($nearby['placeholder']);
        $this->assertCount(12, $nearby['courses']);
        $this->assertTrue($nearby['courses'][0]['same_club']);
    }

    public function test_a_course_without_coordinates_has_no_neighbours(): void
    {
        $subject = $this->course(['course_name' => 'Unplaced', 'lat' => null, 'lng' => null]);
        $this->course(['course_name' => 'Somewhere', 'club_name' => 'Other Club']);

        $nearby = $this->nearby($subject);

        $this->assertSame([], $nearby['courses']);
        $this->assertNull($nearby['placeholder']);
    }

    public function test_a_blank_club_name_no_longer_suppresses_neighbours(): void
    {
        // The old exact-match rule required a club_name and returned nothing
        // without one. Under a radius search a nameless course still has real
        // neighbours — they're just never marked as same-property.
        $subject = $this->course(['course_name' => 'No Club A', 'club_name' => '']);
        $other = $this->courseMilesAway(1, ['course_name' => 'No Club B', 'club_name' => '']);

        $courses = $this->nearby($subject)['courses'];

        $this->assertSame([$other->id], array_column($courses, 'id'));
        $this->assertFalse($courses[0]['same_club']);
    }

    public function test_the_payload_carries_what_the_panel_renders(): void
    {
        $subject = $this->course(['course_name' => 'Subject', 'club_name' => 'Subject Club']);
        $neighbour = $this->courseMilesAway(2, [
            'course_name' => 'Neighbour',
            'club_name' => 'Neighbour Club',
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);

        $row = $this->nearby($subject)['courses'][0];

        $this->assertSame($neighbour->id, $row['id']);
        $this->assertSame('Neighbour', $row['course_name']);
        $this->assertSame('Neighbour Club', $row['club_name']);
        $this->assertSame(18, $row['hole_count']);
        $this->assertFalse($row['green_centers_available']);
        $this->assertSame("/courses/{$neighbour->id}/edit", $row['edit_url']);
    }

    public function test_the_create_page_sends_an_empty_payload_of_the_same_shape(): void
    {
        $this->actingAs($this->editor())
            ->get('/courses/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('nearby.courses', [])
                ->where('nearby.placeholder', null)
                // 5.0 serialises to 5 over the wire.
                ->where('nearby.radius_mi', 5));
    }
}
