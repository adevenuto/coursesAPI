<?php

namespace Tests\Feature\Editor;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseWriteTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['plan' => 'pro', 'role' => 'editor']);
    }

    private function seedGeoNear(): void
    {
        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'latitude' => 38, 'longitude' => -97]);
        State::create(['id' => 10, 'name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'iso2' => 'IL', 'latitude' => 40, 'longitude' => -90]);
        City::create(['id' => 500, 'name' => 'Testville', 'state_id' => 10, 'state_name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 40.0, 'longitude' => -90.0]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'course_name' => 'Test Links',
            'club_name' => 'Test Club',
            'lat' => 40.0,
            'lng' => -90.0,
            'hole_count' => 18,
            'teeboxes' => [[
                'name' => 'Blue',
                'color' => '#1565C0',
                'secondaryColor' => null,
                'slope' => 128,
                'courseRating' => 71.5,
                'totalYardage' => 6500,
                'holes' => [
                    ['hole' => 1, 'par' => 4, 'length' => 410, 'handicap' => 5],
                    ['hole' => 2, 'par' => 3, 'length' => 175, 'handicap' => 11],
                ],
            ]],
            'green_centers' => [
                ['hole' => 1, 'lat' => 40.0011111, 'lng' => -90.0011111],
                ['hole' => 2, 'lat' => 40.0022222, 'lng' => -90.0022222],
            ],
        ], $overrides);
    }

    public function test_store_writes_the_canonical_layout_shape(): void
    {
        $this->seedGeoNear();

        $this->actingAs($this->editor())
            ->post('/courses', $this->payload())
            ->assertRedirect();

        $course = Course::where('course_name', 'Test Links')->firstOrFail();
        $data = $course->layout_data;

        // Teebox shape: order added, color kept, par/length STRINGS, handicap INT.
        $tee = $data['teeboxes'][0];
        $this->assertSame(0, $tee['order']);
        $this->assertSame('Blue', $tee['name']);
        $this->assertSame('#1565C0', $tee['color']);
        $this->assertSame('4', $tee['holes']['hole-1']['par']);
        $this->assertSame('410', $tee['holes']['hole-1']['length']);
        $this->assertSame(5, $tee['holes']['hole-1']['handicap']);

        // Green centers: hole-N object of raw floats + manual provenance.
        $this->assertSame(40.0011111, $data['greenCenters']['hole-1']['lat']);
        $this->assertSame('manual', $data['greenCentersSource']);

        // Accessor round-trips to the list form.
        $this->assertSame([
            ['hole' => 1, 'lat' => 40.0011111, 'lng' => -90.0011111],
            ['hole' => 2, 'lat' => 40.0022222, 'lng' => -90.0022222],
        ], $course->green_centers);

        // Provenance + auto-derived geo.
        $this->assertSame('manual', $course->geo_source);
        $this->assertFalse($course->needs_review);
        $this->assertSame(500, $course->city_id);
        $this->assertSame(10, $course->state_prov_id);
        $this->assertSame(1, $course->country_id);
    }

    public function test_update_preserves_vendor_keys_like_golftraxx(): void
    {
        $this->seedGeoNear();

        $course = Course::create([
            'course_name' => 'Old Name',
            'lat' => 40.0, 'lng' => -90.0,
            'layout_data' => [
                'teeboxes' => [],
                'hole_count' => 18,
                'golftraxx' => ['vendor' => 'blob', 'id' => 999],
                'greenCenterAttemptedAt' => '2026-03-16T04:15:19.747Z',
            ],
        ]);

        $this->actingAs($this->editor())
            ->put("/courses/{$course->id}", $this->payload(['course_name' => 'New Name']))
            ->assertRedirect();

        $data = $course->fresh()->layout_data;

        // The vendor blob and attempt marker survive the edit untouched.
        $this->assertSame(['vendor' => 'blob', 'id' => 999], $data['golftraxx']);
        $this->assertSame('2026-03-16T04:15:19.747Z', $data['greenCenterAttemptedAt']);
        // And the new teeboxes/greens are applied.
        $this->assertSame('Blue', $data['teeboxes'][0]['name']);
        $this->assertSame('New Name', $course->fresh()->course_name);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->post('/courses', $this->payload(['course_name' => '']))
            ->assertSessionHasErrors('course_name');

        $this->actingAs($editor)->post('/courses', $this->payload(['lat' => 200]))
            ->assertSessionHasErrors('lat');

        $bad = $this->payload();
        $bad['teeboxes'][0]['color'] = 'notahexcolor';
        $this->actingAs($editor)->post('/courses', $bad)
            ->assertSessionHasErrors('teeboxes.0.color');

        $bad2 = $this->payload();
        $bad2['green_centers'][0]['hole'] = 99;
        $this->actingAs($editor)->post('/courses', $bad2)
            ->assertSessionHasErrors('green_centers.0.hole');
    }

    public function test_non_editor_cannot_store(): void
    {
        $user = User::factory()->create(['plan' => 'pro', 'role' => 'user']);
        $this->actingAs($user)->post('/courses', $this->payload())->assertForbidden();
    }

    public function test_editor_can_delete_a_course(): void
    {
        $course = Course::create(['course_name' => 'Doomed', 'lat' => 1, 'lng' => 1, 'layout_data' => []]);

        $this->actingAs($this->editor())
            ->delete("/courses/{$course->id}")
            ->assertRedirect(route('explorer'));

        $this->assertModelMissing($course);
    }

    public function test_edit_serialization_preserves_teebox_color(): void
    {
        $course = Course::create([
            'course_name' => 'Colory', 'lat' => 1, 'lng' => 1,
            'layout_data' => [
                'hole_count' => 18,
                'teeboxes' => [[
                    'order' => 0, 'name' => 'Blue', 'color' => '#1565C0',
                    'slope' => 128, 'courseRating' => 71.5, 'totalYardage' => 6500,
                    'holes' => ['hole-1' => ['par' => '4', 'length' => '410', 'handicap' => 5]],
                ]],
                'greenCenters' => ['hole-1' => ['lat' => 1.001, 'lng' => 1.001]],
            ],
        ]);

        $this->actingAs($this->editor())
            ->get("/courses/{$course->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CourseEdit')
                ->where('mode', 'edit')
                ->where('course.teeboxes.0.color', '#1565C0')
                ->where('course.teeboxes.0.holes.0.par', 4) // forEditor normalizes to int
                ->where('course.green_centers.0.hole', 1),
            );
    }
}
