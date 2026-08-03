<?php

namespace Tests\Feature\Explorer;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchableModelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{country:Country,state:State,city:City,course:Course}
     */
    private function seedGeo(): array
    {
        $country = Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'latitude' => 38, 'longitude' => -97]);
        $state = State::create(['id' => 10, 'name' => 'Kentucky', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'iso2' => 'KY', 'latitude' => 37.5, 'longitude' => -85]);
        $city = City::create(['id' => 100, 'name' => 'Bowling Green', 'state_id' => 10, 'state_name' => 'Kentucky', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 37.0, 'longitude' => -86.4]);
        $course = Course::create(['course_name' => 'Riverside', 'club_name' => 'Bowling Green Country Club', 'city_id' => 100, 'state_prov_id' => 10, 'country_id' => 1, 'lat' => 37.01, 'lng' => -86.43, 'layout_data' => []]);

        return compact('country', 'state', 'city', 'course');
    }

    public function test_course_searchable_array_shape(): void
    {
        ['course' => $course] = $this->seedGeo();
        $arr = $course->toSearchableArray();

        $this->assertSame('course', $arr['type']);
        $this->assertSame('Riverside', $arr['name']);
        $this->assertSame('Bowling Green Country Club', $arr['club']);
        $this->assertSame('Bowling Green', $arr['city']);
        $this->assertSame('Kentucky', $arr['state']);
        // club != course → the course is appended after an underscore.
        $this->assertSame('/courses/'.$course->id.'/bowling-green-country-club_riverside', $arr['url']);
        $this->assertArrayHasKey('lat', $arr);
        $this->assertArrayHasKey('label', $arr);
    }

    public function test_url_slug_collapses_when_club_equals_course(): void
    {
        $course = Course::create(['course_name' => 'Pebble Beach', 'club_name' => 'Pebble Beach', 'lat' => 1, 'lng' => 1, 'layout_data' => []]);
        $this->assertSame('pebble-beach', $course->urlSlug());
    }

    public function test_url_slug_falls_back_to_course_when_club_null(): void
    {
        $course = Course::create(['course_name' => 'Rolling Muni', 'club_name' => null, 'lat' => 1, 'lng' => 1, 'layout_data' => []]);
        $this->assertSame('rolling-muni', $course->urlSlug());
    }

    public function test_city_searchable_array_and_gating(): void
    {
        ['city' => $city] = $this->seedGeo();
        $arr = $city->toSearchableArray();

        $this->assertSame('city', $arr['type']);
        $this->assertSame('Bowling Green, Kentucky, United States', $arr['label']);
        $this->assertSame(1, $arr['course_count']);
        $this->assertStringEndsWith('/explore/city/100', $arr['url']);
        $this->assertTrue($city->shouldBeSearchable());

        $empty = City::create(['id' => 200, 'name' => 'Nowhere', 'state_id' => 10, 'country_id' => 1, 'country_code' => 'US', 'latitude' => 0, 'longitude' => 0]);
        $this->assertFalse($empty->shouldBeSearchable());
    }

    public function test_state_and_country_searchable(): void
    {
        ['state' => $state, 'country' => $country] = $this->seedGeo();

        $this->assertSame('state', $state->toSearchableArray()['type']);
        $this->assertTrue($state->shouldBeSearchable());

        $countryArr = $country->toSearchableArray();
        $this->assertSame('country', $countryArr['type']);
        $this->assertSame('US', $countryArr['iso2']);
        $this->assertTrue($country->shouldBeSearchable());
    }

    public function test_index_names_are_prefixed(): void
    {
        $this->assertSame('gca_courses', (new Course)->searchableAs());
        $this->assertSame('gca_cities', (new City)->searchableAs());
        $this->assertSame('gca_states', (new State)->searchableAs());
        $this->assertSame('gca_countries', (new Country)->searchableAs());
    }
}
