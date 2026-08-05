<?php

namespace Tests\Feature\Explorer;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExplorerTest extends TestCase
{
    use RefreshDatabase;

    private function seedGeo(): Course
    {
        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'latitude' => 38, 'longitude' => -97]);
        State::create(['id' => 10, 'name' => 'Kentucky', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'iso2' => 'KY', 'latitude' => 37.5, 'longitude' => -85]);
        City::create(['id' => 100, 'name' => 'Bowling Green', 'state_id' => 10, 'state_name' => 'Kentucky', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 37.0, 'longitude' => -86.4]);

        return Course::create([
            'course_name' => 'Bowling Green Country Club', 'club_name' => 'Bowling Green Country Club',
            'address' => '251 Beech Bend Rd', 'postal_code' => '42101',
            'city_id' => 100, 'state_prov_id' => 10, 'country_id' => 1,
            'lat' => 37.0132, 'lng' => -86.43378, 'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);
    }

    public function test_explore_city_returns_courses_and_bounds_publicly(): void
    {
        $this->seedGeo();

        $this->getJson('/explore/city/100')
            ->assertOk()
            ->assertJsonPath('area.type', 'city')
            ->assertJsonPath('area.id', 100)
            ->assertJsonPath('count', 1)
            ->assertJsonStructure([
                'area' => ['type', 'id', 'name', 'label'],
                'bounds' => ['min_lat', 'max_lat', 'min_lng', 'max_lng'],
                'count', 'returned', 'capped',
                'courses' => [['id', 'name', 'club', 'city', 'state', 'lat', 'lng', 'url']],
            ]);
    }

    public function test_explore_state_and_country_are_public(): void
    {
        $this->seedGeo();

        $this->getJson('/explore/state/10')->assertOk()->assertJsonPath('area.type', 'state');
        $this->getJson('/explore/country/1')->assertOk()->assertJsonPath('area.type', 'country');
    }

    public function test_explore_unknown_area_404s(): void
    {
        $this->getJson('/explore/city/999999')->assertNotFound();
    }

    private function seedRadius(): void
    {
        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'latitude' => 38, 'longitude' => -97]);
        State::create(['id' => 10, 'name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'iso2' => 'IL', 'latitude' => 40, 'longitude' => -89]);
        City::create(['id' => 500, 'name' => 'Testville', 'state_id' => 10, 'state_name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 40.0, 'longitude' => -90.0]);

        // Assigned to the city (exact match).
        Course::create(['course_name' => 'In City', 'club_name' => 'In City', 'city_id' => 500, 'state_prov_id' => 10, 'country_id' => 1, 'lat' => 40.0, 'lng' => -90.0, 'layout_data' => []]);
        // ~4 mi away, NOT assigned to the city.
        Course::create(['course_name' => 'Nearby', 'club_name' => 'Nearby', 'city_id' => null, 'lat' => 40.05, 'lng' => -90.05, 'layout_data' => []]);
        // ~350 mi away.
        Course::create(['course_name' => 'Far', 'club_name' => 'Far', 'city_id' => null, 'lat' => 45.0, 'lng' => -95.0, 'layout_data' => []]);
    }

    public function test_city_radius_returns_nearby_courses_nearest_first_with_distance(): void
    {
        $this->seedRadius();

        // Exact: only the course assigned to the city.
        $this->getJson('/explore/city/500')->assertOk()->assertJsonPath('count', 1);

        // Radius: the nearby course is pulled in, the far one excluded.
        $res = $this->getJson('/explore/city/500?radius=25')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('area.radius_mi', 25)
            ->assertJsonStructure(['area' => ['center' => ['lat', 'lng']], 'courses' => [['distance_mi']]]);

        $courses = $res->json('courses');
        $this->assertCount(2, $courses);
        $this->assertSame('In City', $courses[0]['name']); // nearest first
        $this->assertLessThanOrEqual($courses[1]['distance_mi'], $courses[0]['distance_mi']);
    }

    public function test_radius_is_clamped_to_the_configured_max(): void
    {
        $this->seedRadius();

        // Way over the max (~62 mi) → clamped; far course still excluded.
        $this->getJson('/explore/city/500?radius=9999')
            ->assertOk()
            ->assertJsonPath('area.radius_mi', (int) floor(config('api.max_radius_km') * 0.621371))
            ->assertJsonPath('count', 2);
    }

    public function test_invalid_radius_falls_back_to_exact(): void
    {
        $this->seedRadius();

        foreach (['0', 'abc', '-5'] as $bad) {
            $this->getJson("/explore/city/500?radius={$bad}")
                ->assertOk()
                ->assertJsonPath('count', 1)               // exact behavior
                ->assertJsonMissingPath('area.radius_mi');
        }
    }

    public function test_explorer_page_renders_and_reports_not_configured_without_keys(): void
    {
        // Force the unconfigured state regardless of local .env.
        config(['services.algolia.app_id' => null, 'services.algolia.search_key' => null]);

        $this->get('/explorer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Explorer')
                ->where('algolia.configured', false)
                ->has('algolia.indices.courses')
                ->has('algolia.indices.cities'),
            );
    }

    public function test_explorer_reports_configured_when_keys_present(): void
    {
        config(['services.algolia.app_id' => 'APPID', 'services.algolia.search_key' => 'searchkey']);

        $this->get('/explorer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('algolia.configured', true)
                ->where('algolia.indices.courses', 'gca_courses'),
            );
    }

    public function test_explorer_passes_maps_configuration(): void
    {
        config(['services.google.places_key' => 'MAPSKEY']);
        $this->get('/explorer')
            ->assertInertia(fn (Assert $page) => $page
                ->where('maps.configured', true)
                ->where('maps.key', 'MAPSKEY'));

        config(['services.google.places_key' => null]);
        $this->get('/explorer')
            ->assertInertia(fn (Assert $page) => $page->where('maps.configured', false));
    }

    public function test_course_show_page_renders(): void
    {
        $course = $this->seedGeo();

        $this->get('/courses/'.$course->id.'/'.$course->urlSlug())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CourseShow')
                ->where('course.id', $course->id)
                ->where('course.name', 'Bowling Green Country Club'),
            );
    }

    public function test_course_show_canonicalizes_a_wrong_slug(): void
    {
        $course = $this->seedGeo();

        $this->get('/courses/'.$course->id.'/not-the-right-slug')
            ->assertRedirect('/courses/'.$course->id.'/'.$course->urlSlug());
    }
}
