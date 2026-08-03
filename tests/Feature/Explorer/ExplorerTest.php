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
