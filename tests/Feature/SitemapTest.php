<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private function course(string $name = 'Bowling Green Country Club'): Course
    {
        return Course::create([
            'course_name' => $name,
            'club_name' => $name,
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);
    }

    public function test_index_lists_the_pages_sitemap_and_a_chunk_per_five_thousand_courses(): void
    {
        $this->course();

        $body = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('<sitemapindex', $body);
        $this->assertStringContainsString(route('sitemap.pages'), $body);
        $this->assertStringContainsString(route('sitemap.courses', ['page' => 1]), $body);
        $this->assertSame(1, substr_count($body, '/sitemap/courses-'));
        $this->assertNotFalse(simplexml_load_string($body), 'The sitemap index is not well-formed XML.');
    }

    public function test_pages_sitemap_lists_the_public_marketing_pages(): void
    {
        $body = $this->get('/sitemap/pages.xml')->assertOk()->getContent();

        foreach ([route('home'), route('docs'), route('explorer')] as $url) {
            $this->assertStringContainsString('<loc>'.e($url).'</loc>', $body);
        }

        $this->assertSame(3, substr_count($body, '<url>'));
    }

    public function test_course_chunk_lists_every_course_at_its_canonical_url(): void
    {
        $first = $this->course();
        $second = $this->course('Cumberland Lake Golf Course');

        $body = $this->get('/sitemap/courses-1.xml')->assertOk()->getContent();

        $this->assertSame(2, substr_count($body, '<url>'));
        $this->assertNotFalse(simplexml_load_string($body), 'The course sitemap is not well-formed XML.');

        foreach ([$first, $second] as $course) {
            $this->assertStringContainsString(
                e(route('courses.show', ['course' => $course->id, 'slug' => $course->urlSlug()])),
                $body,
            );
        }
    }

    public function test_a_chunk_beyond_the_course_count_is_not_found(): void
    {
        $this->course();

        $this->get('/sitemap/courses-2.xml')->assertNotFound();
        $this->get('/sitemap/courses-0.xml')->assertNotFound();
    }

    public function test_an_empty_catalogue_still_serves_a_valid_first_chunk(): void
    {
        $body = $this->get('/sitemap/courses-1.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<urlset', $body);
        $this->assertSame(0, substr_count($body, '<url>'));
    }

    public function test_robots_points_at_the_sitemap_and_blocks_private_areas(): void
    {
        $body = $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Sitemap: '.route('sitemap.index'), $body);
        $this->assertStringContainsString('Disallow: /settings/', $body);
        $this->assertStringContainsString('Disallow: /dashboard', $body);
        $this->assertStringContainsString('Disallow: /api/', $body);
        $this->assertStringContainsString('Disallow: /login', $body);
    }
}
