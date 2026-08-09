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

    /**
     * Two states straddling the test coordinate (40.0, -90.0):
     *   IL / Testville  — sits exactly on it, so it always wins on distance
     *   MO / Bordertown — 0.2° away, Farville far off in the corner
     * That makes it possible to prove the address components beat proximity.
     */
    private function seedGeoNear(): void
    {
        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'latitude' => 38, 'longitude' => -97]);
        State::create(['id' => 10, 'name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'iso2' => 'IL', 'iso3166_2' => 'US-IL', 'latitude' => 40, 'longitude' => -90]);
        State::create(['id' => 11, 'name' => 'Missouri', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'iso2' => 'MO', 'iso3166_2' => 'US-MO', 'latitude' => 38, 'longitude' => -92]);
        City::create(['id' => 500, 'name' => 'Testville', 'state_id' => 10, 'state_name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 40.0, 'longitude' => -90.0]);
        City::create(['id' => 501, 'name' => 'Bordertown', 'state_id' => 11, 'state_name' => 'Missouri', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 40.1, 'longitude' => -90.1]);
        City::create(['id' => 502, 'name' => 'Farville', 'state_id' => 11, 'state_name' => 'Missouri', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 41.0, 'longitude' => -91.0]);
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
        // Total yards is resynced to the sum of the hole yards (410 + 175),
        // ignoring the submitted 6500.
        $this->assertSame(585, $tee['totalYardage']);

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

        // Men-only gendered fields stay scalar (byte-consistent with legacy data).
        $this->assertSame(128, $tee['slope']);
        $this->assertSame(71.5, $tee['courseRating']);
    }

    public function test_store_stores_womens_values_as_men_women_arrays(): void
    {
        $this->seedGeoNear();

        $payload = $this->payload();
        $payload['teeboxes'][0]['slope'] = 128;
        $payload['teeboxes'][0]['slopeWomen'] = 120;
        $payload['teeboxes'][0]['courseRating'] = 71.5;
        $payload['teeboxes'][0]['courseRatingWomen'] = 73.4;
        $payload['teeboxes'][0]['holes'][0]['handicap'] = 5;      // hole 1 men + women
        $payload['teeboxes'][0]['holes'][0]['handicapWomen'] = 3;
        $payload['teeboxes'][0]['holes'][1]['handicap'] = 11;     // hole 2 men-only

        $this->actingAs($this->editor())
            ->post('/courses', $payload)
            ->assertRedirect();

        $tee = Course::where('course_name', 'Test Links')->firstOrFail()->layout_data['teeboxes'][0];

        // Both values present → [men, women] array.
        $this->assertSame([128, 120], $tee['slope']);
        $this->assertSame([71.5, 73.4], $tee['courseRating']);
        $this->assertSame([5, 3], $tee['holes']['hole-1']['handicap']);
        // Women's omitted → scalar men's value, unchanged shape.
        $this->assertSame(11, $tee['holes']['hole-2']['handicap']);
    }

    public function test_save_resyncs_total_yardage_from_hole_yards(): void
    {
        $this->seedGeoNear();

        // Submit a deliberately wrong total; save must recompute it from the holes.
        $payload = $this->payload();
        $payload['teeboxes'][0]['totalYardage'] = 9999;

        $this->actingAs($this->editor())
            ->post('/courses', $payload)
            ->assertRedirect();

        $tee = Course::where('course_name', 'Test Links')->firstOrFail()->layout_data['teeboxes'][0];
        $this->assertSame(585, $tee['totalYardage']); // 410 + 175
    }

    public function test_large_summed_total_yardage_validates_and_stores(): void
    {
        $this->seedGeoNear();

        // 18 holes × 800 = 14400, above the old 12000 cap — a derived total must
        // never be rejected on save.
        $holes = [];
        for ($n = 1; $n <= 18; $n++) {
            $holes[] = ['hole' => $n, 'par' => 4, 'length' => 800, 'handicap' => $n];
        }
        $payload = $this->payload();
        $payload['teeboxes'][0]['holes'] = $holes;
        $payload['teeboxes'][0]['totalYardage'] = 14400;

        $this->actingAs($this->editor())
            ->post('/courses', $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $tee = Course::where('course_name', 'Test Links')->firstOrFail()->layout_data['teeboxes'][0];
        $this->assertSame(14400, $tee['totalYardage']);
    }

    public function test_total_yardage_preserved_when_teebox_has_no_hole_yards(): void
    {
        $this->seedGeoNear();

        // No per-hole yards → keep the submitted total (don't wipe a totals-only teebox).
        $payload = $this->payload();
        $payload['teeboxes'][0]['totalYardage'] = 7000;
        $payload['teeboxes'][0]['holes'] = [
            ['hole' => 1, 'par' => 4, 'length' => null, 'handicap' => 5],
            ['hole' => 2, 'par' => 3, 'length' => null, 'handicap' => 11],
        ];

        $this->actingAs($this->editor())
            ->post('/courses', $payload)
            ->assertRedirect();

        $tee = Course::where('course_name', 'Test Links')->firstOrFail()->layout_data['teeboxes'][0];
        $this->assertSame(7000, $tee['totalYardage']);
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

    public function test_place_components_override_the_nearest_city_guess(): void
    {
        $this->seedGeoNear();

        // Testville (IL) is the nearest city to the coordinate, but Google says
        // the address is in Farville, Missouri — the components must win.
        $this->actingAs($this->editor())
            ->post('/courses', $this->payload([
                'place_country_code' => 'US',
                'place_country_name' => 'United States',
                'place_state_code' => 'MO',
                'place_state_name' => 'Missouri',
                'place_city_candidates' => ['Farville'],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $course = Course::where('course_name', 'Test Links')->firstOrFail();
        $this->assertSame(502, $course->city_id);
        $this->assertSame(11, $course->state_prov_id);
        $this->assertSame(1, $course->country_id);
    }

    public function test_unmatched_locality_falls_back_to_the_nearest_city_in_that_state(): void
    {
        $this->seedGeoNear();

        // Google routinely returns a locality the dr5hn dataset doesn't carry.
        // The state still constrains the choice, so it can't land in Illinois.
        $this->actingAs($this->editor())
            ->post('/courses', $this->payload([
                'place_country_code' => 'US',
                'place_state_code' => 'MO',
                'place_state_name' => 'Missouri',
                'place_city_candidates' => ['Nowheresville'],
            ]))
            ->assertRedirect();

        $course = Course::where('course_name', 'Test Links')->firstOrFail();
        $this->assertSame(501, $course->city_id); // Bordertown, the closest MO city
        $this->assertSame(11, $course->state_prov_id);
        $this->assertSame(1, $course->country_id);
    }

    public function test_country_only_components_still_constrain_the_lookup(): void
    {
        $this->seedGeoNear();

        $this->actingAs($this->editor())
            ->post('/courses', $this->payload(['place_country_code' => 'US']))
            ->assertRedirect();

        $course = Course::where('course_name', 'Test Links')->firstOrFail();
        $this->assertSame(1, $course->country_id);
        $this->assertSame(500, $course->city_id); // nearest city within the US
        $this->assertSame(10, $course->state_prov_id);
    }

    public function test_save_without_a_picked_place_leaves_the_stored_geo_alone(): void
    {
        $this->seedGeoNear();

        // A previously corrected course: the stored city is NOT the nearest one.
        $course = Course::create([
            'course_name' => 'Old Name',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 502, 'state_prov_id' => 11, 'country_id' => 1,
            'layout_data' => [],
        ]);

        // Editing anything else at the same coordinates must not re-guess. The
        // form always posts the place_* keys, empty when nothing was picked.
        $this->actingAs($this->editor())
            ->put("/courses/{$course->id}", $this->payload([
                'phone' => '555-0100',
                'place_country_code' => '',
                'place_country_name' => '',
                'place_state_code' => '',
                'place_state_name' => '',
                'place_city_candidates' => [],
            ]))
            ->assertRedirect();

        $course->refresh();
        $this->assertSame(502, $course->city_id);
        $this->assertSame(11, $course->state_prov_id);
        $this->assertSame(1, $course->country_id);
    }

    public function test_moving_the_coordinates_re_derives_the_geo(): void
    {
        $this->seedGeoNear();

        $course = Course::create([
            'course_name' => 'Old Name',
            'lat' => 41.0, 'lng' => -91.0,
            'city_id' => 502, 'state_prov_id' => 11, 'country_id' => 1,
            'layout_data' => [],
        ]);

        $this->actingAs($this->editor())
            ->put("/courses/{$course->id}", $this->payload()) // moves to 40.0 / -90.0
            ->assertRedirect();

        $this->assertSame(500, $course->refresh()->city_id);
    }

    public function test_a_location_correction_is_recorded_in_the_history(): void
    {
        $this->seedGeoNear();

        $course = Course::create([
            'course_name' => 'Old Name',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'layout_data' => [],
        ]);

        $this->actingAs($this->editor())
            ->put("/courses/{$course->id}", $this->payload([
                'place_country_code' => 'US',
                'place_state_code' => 'MO',
                'place_city_candidates' => ['Farville'],
            ]))
            ->assertRedirect();

        $labels = collect($course->revisions()->first()->changes)->pluck('label');
        $this->assertTrue($labels->contains('Location'));
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
