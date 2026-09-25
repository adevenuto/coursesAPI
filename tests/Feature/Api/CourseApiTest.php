<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Support\Distance;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CourseApiTest extends ApiTestCase
{
    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/courses')->assertUnauthorized();
    }

    public function test_api_requests_are_counted_for_usage(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses')->assertOk();
        $this->getJson('/api/v1/courses')->assertOk();

        $row = DB::table('api_usage')
            ->where('user_id', $this->freeUser->id)
            ->where('usage_date', now()->toDateString())
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->requests);
    }

    public function test_it_lists_courses_paginated_with_clean_meta(): void
    {
        Sanctum::actingAs($this->freeUser);

        $res = $this->getJson('/api/v1/courses?per_page=2');

        $res->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'club', 'city', 'state', 'country', 'latitude', 'longitude']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ])
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        // Verbose Laravel pagination links[] array must be gone.
        $this->assertArrayNotHasKey('links', $res->json('meta'));
    }

    public function test_it_searches_by_name(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses?q=bowling')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Bowling Green Country Club');
    }

    public function test_it_filters_by_country_iso2(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses?country=US')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/courses?country=CA')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_near_me_returns_distance_sorted_within_radius(): void
    {
        Sanctum::actingAs($this->freeUser);

        $res = $this->getJson('/api/v1/courses?lat=37.0132&lng=-86.43378&radius=25')->assertOk();

        // Two KY courses within 25 miles; Pebble Beach (CA) excluded.
        $res->assertJsonPath('meta.total', 2);
        $this->assertSame('Bowling Green Country Club', $res->json('data.0.name'));
        $this->assertLessThan((float) $res->json('data.1.distance_mi'), (float) $res->json('data.0.distance_mi'));
        $this->assertArrayHasKey('distance_mi', $res->json('data.0'));
    }

    /**
     * The two KY courses sit 3.72 km — 2.31 mi — apart, so a radius of 3 covers
     * both when it means miles and only the first when it means kilometres.
     * That gap is what pins the default down.
     */
    public function test_radius_defaults_to_miles(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses?lat=37.0132&lng=-86.43378&radius=3')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/courses?lat=37.0132&lng=-86.43378&radius=3&units=km')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * Both distance keys ship on every near-me result, whatever `units` said:
     * that parameter chooses how `radius` is read, not which distance comes
     * back. Both are permanent — the audience spans miles and kilometres, and
     * dropping either would only buy a future breaking change. This test is
     * what keeps them there.
     */
    public function test_both_distance_units_are_always_returned(): void
    {
        Sanctum::actingAs($this->freeUser);

        foreach (['', '&units=mi', '&units=km'] as $suffix) {
            $row = $this->getJson("/api/v1/courses?lat=37.0132&lng=-86.43378&radius=25{$suffix}")
                ->assertOk()
                ->json('data.1');

            $this->assertArrayHasKey('distance_mi', $row, "units suffix: '{$suffix}'");
            $this->assertArrayHasKey('distance_km', $row, "units suffix: '{$suffix}'");

            // One distance, two readings.
            $this->assertEqualsWithDelta(
                (float) $row['distance_km'] * Distance::MILES_PER_KM,
                (float) $row['distance_mi'],
                0.01,
                "units suffix: '{$suffix}'",
            );
        }
    }

    /**
     * Omitting `radius` searches to the cap, and the cap moved with the unit —
     * so `units=km` alone does NOT reproduce the old 100km default. Pinning
     * this because the docs tell callers to send radius=100&units=km for that.
     */
    public function test_omitted_radius_searches_to_the_cap_in_the_requested_unit(): void
    {
        Sanctum::actingAs($this->freeUser);

        // Pebble Beach is ~3000km away: out of range either way, so the cap
        // itself is what this asserts, via the request that defines it.
        $this->getJson('/api/v1/courses?lat=37.0132&lng=-86.43378')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->assertSame(100.0, Distance::maxRadius(Distance::MI));
        $this->assertSame(161.0, Distance::maxRadius(Distance::KM));
    }

    public function test_radius_cap_follows_the_requested_unit(): void
    {
        Sanctum::actingAs($this->freeUser);

        $base = '/api/v1/courses?lat=37.0132&lng=-86.43378';

        // 100 miles is the cap, so the mile ceiling is lower in raw numbers...
        $this->getJson("{$base}&radius=100")->assertOk();
        $this->getJson("{$base}&radius=101")
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('radius');

        // ...than the kilometre one, which is the same distance rounded up.
        $this->getJson("{$base}&radius=161&units=km")->assertOk();
        $this->getJson("{$base}&radius=162&units=km")
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('radius');
    }

    public function test_unknown_units_are_rejected(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses?lat=37.0132&lng=-86.43378&radius=25&units=furlongs')
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('units');
    }

    public function test_per_page_is_capped(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses?per_page=999')
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('per_page');
    }

    public function test_show_returns_scorecard_with_mens_and_womens_values(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson("/api/v1/courses/{$this->bgCourse->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->bgCourse->id)
            ->assertJsonPath('data.location.country.iso2', 'US')
            ->assertJsonPath('data.scorecard.hole_count', 18)
            ->assertJsonPath('data.scorecard.teeboxes.0.name', 'Gold')
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.0.par', 4)
            // Men's values under the base keys; women's alongside as *_women.
            ->assertJsonPath('data.scorecard.teeboxes.0.rating', 73.3)
            ->assertJsonPath('data.scorecard.teeboxes.0.rating_women', 71.2)
            ->assertJsonPath('data.scorecard.teeboxes.0.slope', 128)
            ->assertJsonPath('data.scorecard.teeboxes.0.slope_women', 120)
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.0.handicap', 7)
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.0.handicap_women', 5)
            // hole-2 has no women's handicap → handicap_women falls back to men's.
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.1.handicap', 1)
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.1.handicap_women', 1);
    }

    public function test_women_fields_fall_back_to_men_when_course_has_no_womens_data(): void
    {
        Sanctum::actingAs($this->freeUser);

        // Men-only course: slope, rating, and handicap are all scalar (no women's).
        $menOnly = Course::create([
            'course_name' => 'Men Only GC',
            'city_id' => 100, 'state_prov_id' => 10, 'country_id' => 1,
            'lat' => 37.01, 'lng' => -86.44,
            'layout_data' => [
                'hole_count' => 18,
                'teeboxes' => [[
                    'name' => 'Blue', 'courseRating' => 72.4, 'slope' => 131,
                    'holes' => ['hole-1' => ['par' => '4', 'length' => '400', 'handicap' => 9]],
                ]],
            ],
        ]);

        // Every *_women field mirrors the men's value.
        $this->getJson("/api/v1/courses/{$menOnly->id}")
            ->assertOk()
            ->assertJsonPath('data.scorecard.teeboxes.0.rating', 72.4)
            ->assertJsonPath('data.scorecard.teeboxes.0.rating_women', 72.4)
            ->assertJsonPath('data.scorecard.teeboxes.0.slope', 131)
            ->assertJsonPath('data.scorecard.teeboxes.0.slope_women', 131)
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.0.handicap', 9)
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.0.handicap_women', 9);
    }

    /**
     * The forward tee on a card that rates the back tees for men only. The
     * stored shape is [null, women]; a bare (float) cast would report the
     * men's rating as 0.0 rather than absent.
     */
    public function test_a_women_only_tee_reports_null_men_not_zero(): void
    {
        Sanctum::actingAs($this->freeUser);

        $course = Course::create([
            'course_name' => 'Ladies Tee GC',
            'city_id' => 100, 'state_prov_id' => 10, 'country_id' => 1,
            'lat' => 37.02, 'lng' => -86.45,
            'layout_data' => [
                'hole_count' => 18,
                'teeboxes' => [[
                    'name' => 'Red',
                    'courseRating' => [null, 56.1],
                    'slope' => [null, 86],
                    'holes' => ['hole-1' => ['par' => '4', 'length' => '278', 'handicap' => [null, 5]]],
                ]],
            ],
        ]);

        $this->getJson("/api/v1/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.scorecard.teeboxes.0.rating', null)
            ->assertJsonPath('data.scorecard.teeboxes.0.slope', null)
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.0.handicap', null)
            ->assertJsonPath('data.scorecard.teeboxes.0.rating_women', 56.1)
            ->assertJsonPath('data.scorecard.teeboxes.0.slope_women', 86)
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.0.handicap_women', 5);
    }

    public function test_show_404_for_missing_course(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses/99999999')->assertNotFound();
    }
}
