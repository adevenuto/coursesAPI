<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Head\Facades\Head;
use Tests\TestCase;

/**
 * These assertions deliberately inspect the raw HTML rather than Inertia props:
 * the whole point of Laravel Head here is that crawlers and link-preview bots
 * see the tags without executing any JavaScript.
 */
class HeadTest extends TestCase
{
    use RefreshDatabase;

    private function seedCourse(): Course
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

    public function test_landing_page_ships_full_metadata_in_the_html(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title data-inertia="title">The Golf Courses API</title>', $html);
        $this->assertStringContainsString('name="description"', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('property="og:site_name" content="GCA"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('name="robots" content="all"', $html);
    }

    public function test_landing_page_carries_organization_and_website_schema(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('"@type":"WebSite"', $html);
    }

    public function test_every_site_name_signal_agrees(): void
    {
        // Google builds the site name it appends to result titles from these,
        // weighting WebSite structured data highest. When they disagreed, two
        // pages got different suffixes and one rendered the brand twice.
        $html = $this->get('/')->assertOk()->getContent();

        preg_match('/property="og:site_name" content="([^"]*)"/', $html, $og);

        $schemaNames = [];

        preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $blocks);

        foreach ($blocks[1] as $json) {
            $decoded = json_decode($json, true);

            if (in_array($decoded['@type'] ?? null, ['Organization', 'WebSite'], true)) {
                $schemaNames[$decoded['@type']] = $decoded['name'] ?? null;
            }
        }

        $this->assertSame('GCA', $og[1] ?? null, 'og:site_name');
        $this->assertSame('GCA', $schemaNames['Organization'] ?? null, 'Organization name');
        $this->assertSame('GCA', $schemaNames['WebSite'] ?? null, 'WebSite name');
    }

    public function test_the_home_title_does_not_repeat_the_site_name(): void
    {
        // Google appends the site name, so a title containing it renders twice.
        preg_match(
            '/<title[^>]*>([^<]*)</',
            $this->get('/')->assertOk()->getContent(),
            $title,
        );

        $this->assertStringNotContainsString('GCA', $title[1]);
    }

    public function test_static_pages_use_their_own_titles(): void
    {
        $this->assertStringContainsString(
            '<title data-inertia="title">API Documentation — GCA</title>',
            $this->get('/docs')->assertOk()->getContent(),
        );

        $this->assertStringContainsString(
            '<title data-inertia="title">Course Explorer — GCA</title>',
            $this->get('/explorer')->assertOk()->getContent(),
        );
    }

    public function test_course_page_metadata_is_built_from_the_course(): void
    {
        $course = $this->seedCourse();

        $html = $this->get(route('courses.show', ['course' => $course->id, 'slug' => $course->urlSlug()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<title data-inertia="title">Bowling Green Country Club — GCA</title>', $html);
        $this->assertStringContainsString('Bowling Green, Kentucky, United States', $html);
        $this->assertStringContainsString('"@type":"GolfCourse"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"latitude":37.0132', $html);
    }

    public function test_course_schema_omits_missing_values_rather_than_writing_blanks(): void
    {
        // A name-only course: no address, coordinates, phone or website. The
        // schema validator rejects null/empty values, so those keys must be
        // absent entirely rather than present and empty.
        $course = Course::create([
            'course_name' => 'Sparse Links',
            'club_name' => 'Sparse Links',
            'layout_data' => ['hole_count' => 9, 'teeboxes' => []],
        ]);

        $html = $this->get(route('courses.show', ['course' => $course->id, 'slug' => $course->urlSlug()]))
            ->assertOk()
            ->getContent();

        // Assert against the schema block itself — the Inertia page props also
        // carry an `address` key (set to null), which is not what's under test.
        $schema = $this->golfCourseSchema($html);

        $this->assertSame(['@context', '@type', 'name', 'url'], array_keys($schema));
        $this->assertSame('Sparse Links', $schema['name']);
    }

    /**
     * Pull the GolfCourse JSON-LD block out of a rendered page.
     *
     * @return array<string, mixed>
     */
    private function golfCourseSchema(string $html): array
    {
        preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);

            if (($decoded['@type'] ?? null) === 'GolfCourse') {
                return $decoded;
            }
        }

        $this->fail('No GolfCourse JSON-LD block was rendered.');
    }

    public function test_a_course_named_like_a_number_still_renders(): void
    {
        // 42 real courses are named "2018", "2004" and so on. Passing such a
        // name as an array key casts it to int and blows up the breadcrumb
        // builder, which 500'd the page.
        $course = Course::create([
            'course_name' => '2018',
            'club_name' => 'Sobienie Krolewskie GCC',
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);

        $html = $this->get(route('courses.show', ['course' => $course->id, 'slug' => $course->urlSlug()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<title data-inertia="title">2018 — GCA</title>', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"name":"2018"', $html);
    }

    public function test_course_canonical_points_at_the_slugged_url(): void
    {
        $course = $this->seedCourse();

        $html = $this->get(route('courses.show', ['course' => $course->id, 'slug' => $course->urlSlug()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('rel="canonical" href="https://localhost:8000/courses/'.$course->id.'/bowling-green-country-club"', $html);
    }

    public function test_private_pages_are_hidden_from_search_engines(): void
    {
        $user = User::factory()->create();

        foreach (['/dashboard', '/settings/profile'] as $path) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        }
    }

    public function test_auth_pages_are_titled_and_hidden_from_search_engines(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<title data-inertia="title">Log in — GCA</title>', $html);
        $this->assertStringContainsString('name="robots" content="none"', $html);
    }

    public function test_missing_courses_return_a_404(): void
    {
        $this->get('/courses/999999999')->assertNotFound();
    }

    public function test_error_metadata_resolves_as_noindex(): void
    {
        // NOTE: this asserts the resolved metadata, not rendered HTML. Error
        // responses currently use Laravel's built-in error views, which don't
        // render @head — so these tags only reach the page if error pages are
        // ever routed through the app layout. The 404 *status* is what matters
        // to crawlers, and that is covered above.
        $resolved = Head::toArray(404);

        $this->assertSame('noindex, follow', $resolved['robots'] ?? null);
        $this->assertStringContainsString('Page Not Found', $resolved['title'] ?? '');
    }
}
