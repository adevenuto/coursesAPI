<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $freeUser;

    protected User $proUser;

    protected Course $bgCourse;      // Bowling Green CC — has green centers

    protected Course $noGreenCourse; // located, no green centers

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA']);
        $ky = State::create(['id' => 10, 'name' => 'Kentucky', 'country_id' => 1, 'country_code' => 'US', 'iso2' => 'KY']);
        $city = City::create([
            'id' => 100, 'name' => 'Bowling Green', 'state_id' => 10, 'country_id' => 1,
            'country_code' => 'US', 'latitude' => 37.0, 'longitude' => -86.4,
        ]);

        $this->bgCourse = Course::create([
            'course_name' => 'Bowling Green Country Club',
            'club_name' => 'Bowling Green Country Club',
            'address' => '251 Beech Bend Rd, Bowling Green, KY 42101, USA',
            'postal_code' => '42101',
            'city_id' => 100, 'state_prov_id' => 10, 'country_id' => 1,
            'lat' => 37.0132, 'lng' => -86.43378,
            'layout_data' => [
                'hole_count' => 18,
                'teeboxes' => [[
                    'name' => 'Gold', 'courseRating' => 73.3, 'slope' => 128, 'totalYardage' => 6800,
                    'holes' => [
                        'hole-1' => ['par' => '4', 'length' => '437', 'handicap' => 7],
                        'hole-2' => ['par' => '5', 'length' => '518', 'handicap' => 1],
                    ],
                ]],
                'greenCenters' => [
                    'hole-1' => ['lat' => 37.017442, 'lng' => -86.431353],
                    'hole-2' => ['lat' => 37.019378, 'lng' => -86.434877],
                ],
            ],
        ]);

        // Nearby course (for near-me), no green centers.
        $this->noGreenCourse = Course::create([
            'course_name' => 'Indian Hills Country Club',
            'club_name' => 'Indian Hills Country Club',
            'city_id' => 100, 'state_prov_id' => 10, 'country_id' => 1,
            'lat' => 36.993816, 'lng' => -86.400566,
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);

        // Far-away course (California) — should be excluded from a KY radius search.
        Course::create([
            'course_name' => 'Pebble Beach Golf Links',
            'club_name' => 'Pebble Beach',
            'city_id' => 100, 'state_prov_id' => 10, 'country_id' => 1,
            'lat' => 36.5674, 'lng' => -121.9490,
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);

        $this->freeUser = User::factory()->create(['plan' => 'free']);
        $this->proUser = User::factory()->create(['plan' => 'pro']);
    }
}
