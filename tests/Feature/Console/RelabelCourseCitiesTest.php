<?php

namespace Tests\Feature\Console;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelabelCourseCitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ohio holds Smallville (the nearest centroid, tiny) and Bigtown (the city
     * the address names, 100×). Farville is a second Bigtown-sized place far
     * away; Duptown appears twice at different distances. Illinois exists only
     * to prove candidates are scoped to the course's own state.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA']);
        State::create(['id' => 10, 'name' => 'Ohio', 'country_id' => 1, 'country_code' => 'US', 'iso2' => 'OH']);
        State::create(['id' => 11, 'name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'iso2' => 'IL']);

        $this->city(500, 'Smallville', 10, 1_700, 40.00, -84.00);
        $this->city(501, 'Bigtown', 10, 311_000, 40.05, -84.05);
        $this->city(502, 'Twiceover', 10, 60_000, 40.02, -84.02);  // near
        $this->city(503, 'Twiceover', 10, 900_000, 44.00, -88.00); // far but huge
        $this->city(504, 'Hamlet', 10, 900, 40.03, -84.03);        // smaller than stored
        $this->city(505, 'Distant', 10, 500_000, 45.00, -89.00);   // >50km away
        $this->city(506, 'Modest', 10, 4_000, 40.04, -84.04);      // fails the ratio
        $this->city(600, 'Bigtown', 11, 900_000, 41.00, -88.00);   // same name, other state
    }

    private function city(int $id, string $name, int $stateId, int $pop, float $lat, float $lng): void
    {
        City::create([
            'id' => $id, 'name' => $name, 'state_id' => $stateId, 'country_id' => 1,
            'country_code' => 'US', 'population' => $pop, 'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    private function course(string $addressCity, array $overrides = []): Course
    {
        return Course::create(array_merge([
            'course_name' => 'Test Links',
            'address' => "1 Fairway Dr, {$addressCity}, OH 45239, USA",
            'lat' => 40.00, 'lng' => -84.00,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'layout_data' => [],
        ], $overrides));
    }

    public function test_it_relabels_to_the_city_the_address_names(): void
    {
        $course = $this->course('Bigtown', ['geo_source' => 'google']);

        $this->artisan('courses:relabel-cities --apply')->assertSuccessful();

        $course->refresh();
        $this->assertSame(501, $course->city_id);
        // Everything else is off limits.
        $this->assertSame(10, $course->state_prov_id);
        $this->assertSame(1, $course->country_id);
        $this->assertSame('1 Fairway Dr, Bigtown, OH 45239, USA', $course->address);
        $this->assertSame('google', $course->geo_source);
    }

    public function test_it_leaves_a_city_that_is_not_materially_bigger(): void
    {
        // Modest is 4,000 against Smallville's 1,700 — over 2x but under the
        // 10,000 floor, and under the 3x ratio.
        $course = $this->course('Modest');

        $this->artisan('courses:relabel-cities --apply')->assertSuccessful();

        $this->assertSame(500, $course->refresh()->city_id);
    }

    public function test_it_never_moves_to_a_smaller_city(): void
    {
        $course = $this->course('Hamlet');

        $this->artisan('courses:relabel-cities --apply')->assertSuccessful();

        $this->assertSame(500, $course->refresh()->city_id);
    }

    public function test_it_picks_the_nearer_of_two_same_named_cities(): void
    {
        // Twiceover exists at 3km (60k people) and 450km (900k people). Proximity
        // identifies which one the address means; size only then decides.
        $course = $this->course('Twiceover');

        $this->artisan('courses:relabel-cities --apply')->assertSuccessful();

        $this->assertSame(502, $course->refresh()->city_id);
    }

    public function test_it_rejects_a_same_named_city_beyond_the_distance_cap(): void
    {
        $course = $this->course('Distant');

        $this->artisan('courses:relabel-cities --apply --max-km=50')->assertSuccessful();

        $this->assertSame(500, $course->refresh()->city_id);
    }

    public function test_candidates_are_scoped_to_the_courses_own_state(): void
    {
        // An Illinois course whose address names Bigtown must not pick up Ohio's.
        $course = Course::create([
            'course_name' => 'Cross Border',
            'address' => '1 Fairway Dr, Bigtown, IL 60000, USA',
            'lat' => 41.00, 'lng' => -88.00,
            'city_id' => 600, 'state_prov_id' => 11, 'country_id' => 1,
            'layout_data' => [],
        ]);

        $this->artisan('courses:relabel-cities --apply')->assertSuccessful();

        $course->refresh();
        $this->assertSame(600, $course->city_id);
        $this->assertSame(11, $course->state_prov_id);
    }

    public function test_it_skips_courses_a_human_verified(): void
    {
        $course = $this->course('Bigtown', ['geo_source' => 'manual']);

        $this->artisan('courses:relabel-cities --apply')->assertSuccessful();

        $this->assertSame(500, $course->refresh()->city_id);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $course = $this->course('Bigtown');

        $this->artisan('courses:relabel-cities')->assertSuccessful();

        $this->assertSame(500, $course->refresh()->city_id);
    }

    public function test_it_is_idempotent(): void
    {
        $course = $this->course('Bigtown');

        $this->artisan('courses:relabel-cities --apply')->assertSuccessful();
        $this->assertSame(501, $course->refresh()->city_id);

        // Second pass: the stored city now equals the address, so nothing to do.
        $this->artisan('courses:relabel-cities --apply')
            ->expectsOutputToContain('Applied.')
            ->assertSuccessful();

        $this->assertSame(501, $course->refresh()->city_id);
    }

    public function test_it_reports_cities_left_with_no_courses(): void
    {
        $this->course('Bigtown'); // Smallville's only course

        $this->artisan('courses:relabel-cities --apply --no-index')
            ->expectsOutputToContain('1 cities are left with no courses')
            ->assertSuccessful();

        $this->assertSame(0, Course::where('city_id', 500)->count());
    }
}
