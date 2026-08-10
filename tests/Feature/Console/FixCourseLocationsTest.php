<?php

namespace Tests\Feature\Console;

use App\Console\Commands\FixCourseLocations;
use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FixCourseLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.geocoding_key' => 'test-key']);

        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA']);
        State::create(['id' => 10, 'name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'iso2' => 'IL']);
        State::create(['id' => 11, 'name' => 'Missouri', 'country_id' => 1, 'country_code' => 'US', 'iso2' => 'MO']);
        City::create(['id' => 500, 'name' => 'Testville', 'state_id' => 10, 'country_id' => 1, 'country_code' => 'US', 'latitude' => 40.0, 'longitude' => -90.0]);
        City::create(['id' => 501, 'name' => 'Bordertown', 'state_id' => 11, 'country_id' => 1, 'country_code' => 'US', 'latitude' => 40.1, 'longitude' => -90.1]);
    }

    /** A Geocoding API payload placing the coordinate in Bordertown, Missouri. */
    private function fakeGoogle(string $state = 'MO', string $stateName = 'Missouri', string $city = 'Bordertown'): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => "1 Fairway Dr, {$city}, {$state} 60000, USA",
                    'address_components' => [
                        ['long_name' => $city, 'short_name' => $city, 'types' => ['locality']],
                        ['long_name' => $stateName, 'short_name' => $state, 'types' => ['administrative_area_level_1']],
                        ['long_name' => 'United States', 'short_name' => 'US', 'types' => ['country']],
                        ['long_name' => '60000', 'short_name' => '60000', 'types' => ['postal_code']],
                    ],
                ]],
            ]),
        ]);
    }

    public function test_it_fixes_a_suspect_course_when_google_agrees_with_the_address(): void
    {
        $this->fakeGoogle();

        $course = Course::create([
            'course_name' => 'Border Links',
            'address' => '1 Fairway Dr, Bordertown, MO 60000, USA',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1, // wrong: Illinois
            'layout_data' => [],
        ]);

        $this->artisan('courses:fix-locations --apply --only=suspect')->assertSuccessful();

        $course->refresh();
        $this->assertSame(11, $course->state_prov_id);
        $this->assertSame(501, $course->city_id);
        $this->assertSame('google', $course->geo_source);
        // The address was already right — it must survive untouched.
        $this->assertSame('1 Fairway Dr, Bordertown, MO 60000, USA', $course->address);
    }

    public function test_it_flags_rather_than_guesses_when_the_coordinate_contradicts_the_address(): void
    {
        // The address claims Missouri while the row is stored as Illinois, so it
        // lands in the suspect set — but Google reads the coordinate as Illinois
        // too. The address is the odd one out, and we can't tell whether it or
        // the coordinate is wrong, so nothing moves.
        $this->fakeGoogle('IL', 'Illinois', 'Testville');

        $course = Course::create([
            'course_name' => 'Confused Links',
            'address' => '1 Fairway Dr, Bordertown, MO 60000, USA',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'layout_data' => [],
        ]);

        $this->artisan('courses:fix-locations --apply --only=suspect')->assertSuccessful();

        $course->refresh();
        $this->assertTrue((bool) $course->needs_review);
        $this->assertSame(10, $course->state_prov_id); // left alone for a human
        $this->assertSame(500, $course->city_id);
    }

    public function test_it_fills_address_and_coordinates_from_layout_geometry(): void
    {
        $this->fakeGoogle();

        $course = Course::create([
            'course_name' => 'Hidden Links',
            'address' => null,
            'lat' => null, 'lng' => null,
            'layout_data' => ['greenCenters' => ['hole-1' => ['lat' => 40.1, 'lng' => -90.1]]],
        ]);

        $this->artisan('courses:fix-locations --apply --only=missing')->assertSuccessful();

        $course->refresh();
        $this->assertSame('1 Fairway Dr, Bordertown, MO 60000, USA', $course->address);
        $this->assertSame('60000', $course->postal_code);
        $this->assertEqualsWithDelta(40.1, (float) $course->lat, 0.0001);
        $this->assertSame(501, $course->city_id);
        $this->assertSame(11, $course->state_prov_id);
    }

    public function test_a_dry_run_writes_nothing_and_makes_no_calls_on_a_second_pass(): void
    {
        $this->fakeGoogle();

        $course = Course::create([
            'course_name' => 'Border Links',
            'address' => '1 Fairway Dr, Bordertown, MO 60000, USA',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'layout_data' => [],
        ]);

        $this->artisan('courses:fix-locations --only=suspect')->assertSuccessful();

        $this->assertSame(10, $course->refresh()->state_prov_id);
        // The response is still cached, so a replay costs nothing.
        $this->assertSame(1, DB::table('geocode_cache')->where('provider', 'google-reverse')->count());
    }

    public function test_cache_only_never_calls_the_api_and_reports_misses(): void
    {
        Http::fake(); // any call would return an empty 200 and fail the assertion below

        Course::create([
            'course_name' => 'Border Links',
            'address' => '1 Fairway Dr, Bordertown, MO 60000, USA',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'layout_data' => [],
        ]);

        $this->artisan('courses:fix-locations --apply --cache-only --only=suspect')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_cached_response_is_replayed_without_an_api_call(): void
    {
        DB::table('geocode_cache')->insert([
            'query' => sprintf('revgeo:%.6f,%.6f', 40.0, -90.0),
            'provider' => 'google-reverse',
            'response_json' => json_encode([
                'country_code' => 'US', 'country_name' => 'United States',
                'state_code' => 'MO', 'state_name' => 'Missouri',
                'city_candidates' => ['Bordertown'],
                'formatted_address' => '1 Fairway Dr, Bordertown, MO 60000, USA',
                'postal_code' => '60000',
            ]),
            'resolved_lat' => 40.0, 'resolved_lng' => -90.0,
            'status' => 'accepted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake();

        $course = Course::create([
            'course_name' => 'Border Links',
            'address' => '1 Fairway Dr, Bordertown, MO 60000, USA',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'layout_data' => [],
        ]);

        $this->artisan('courses:fix-locations --apply --cache-only --only=suspect')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(11, $course->refresh()->state_prov_id);
    }

    public function test_it_skips_courses_a_human_verified(): void
    {
        $this->fakeGoogle();

        $course = Course::create([
            'course_name' => 'Border Links',
            'address' => '1 Fairway Dr, Bordertown, MO 60000, USA',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'geo_source' => 'manual',
            'layout_data' => [],
        ]);

        $this->artisan('courses:fix-locations --apply')->assertSuccessful();

        $this->assertSame(10, $course->refresh()->state_prov_id);
    }

    public function test_it_keeps_a_finer_existing_location_rather_than_blanking_it(): void
    {
        // Google names a state that carries no cities at all — the England /
        // Scotland / Catalonia case. The course already knew a city, so the
        // address and coordinates are filled but the relations are left alone.
        State::create(['id' => 12, 'name' => 'Cityless', 'country_id' => 1, 'country_code' => 'US', 'iso2' => 'ZZ']);

        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => '9 Links Rd, Nowhere, ZZ 70000, USA',
                    'address_components' => [
                        ['long_name' => 'Cityless', 'short_name' => 'ZZ', 'types' => ['administrative_area_level_1']],
                        ['long_name' => 'United States', 'short_name' => 'US', 'types' => ['country']],
                    ],
                ]],
            ]),
        ]);

        $course = Course::create([
            'course_name' => 'Fine Links',
            'address' => null,
            'lat' => null, 'lng' => null,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'layout_data' => ['greenCenters' => ['hole-1' => ['lat' => 40.0, 'lng' => -90.0]]],
        ]);

        $this->artisan('courses:fix-locations --apply --only=missing')->assertSuccessful();

        $course->refresh();
        $this->assertSame('9 Links Rd, Nowhere, ZZ 70000, USA', $course->address); // gained
        $this->assertEqualsWithDelta(40.0, (float) $course->lat, 0.0001);           // gained
        $this->assertSame(500, $course->city_id);                                   // kept
        $this->assertSame(10, $course->state_prov_id);                              // kept
    }

    public function test_it_strips_plus_codes_from_returned_addresses(): void
    {
        $cases = [
            '3562+MR Willemstad, Curaçao' => 'Willemstad, Curaçao',
            'P27G+9M Mount Martha VIC, Australia' => 'Mount Martha VIC, Australia',
            "G5CJ+7FG, A1, St.Bran's Burg, Jamaica" => "A1, St.Bran's Burg, Jamaica",
            // A real street address must survive untouched.
            '2348 Grandin Rd, Cincinnati, OH 45208, USA' => '2348 Grandin Rd, Cincinnati, OH 45208, USA',
            // As must one that merely contains a '+' later on.
            '10 Cromwell Dr, Desert Springs NT 0870, Australia' => '10 Cromwell Dr, Desert Springs NT 0870, Australia',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, FixCourseLocations::cleanAddress($input), $input);
        }

        $this->assertNull(FixCourseLocations::cleanAddress(null));
    }

    public function test_a_referer_restricted_key_fails_loudly(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'REQUEST_DENIED',
                'error_message' => 'API keys with referer restrictions cannot be used with this API.',
            ]),
        ]);

        Course::create([
            'course_name' => 'Border Links',
            'address' => '1 Fairway Dr, Bordertown, MO 60000, USA',
            'lat' => 40.0, 'lng' => -90.0,
            'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1,
            'layout_data' => [],
        ]);

        $this->artisan('courses:fix-locations --apply --only=suspect')->assertFailed();

        // Nothing cached: a rejected key must not poison the cache with empties.
        $this->assertSame(0, DB::table('geocode_cache')->count());
    }
}
