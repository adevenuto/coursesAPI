<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * XML sitemaps for search engines.
 *
 * Generated on request and cached rather than written to disk: this host has no
 * cron to regenerate a file, and anything written into the app's public/ folder
 * would be removed by the deploy's `rsync --delete`. Course data only changes
 * through the editor, so a day-long cache costs one query per chunk per day.
 */
class SitemapController extends Controller
{
    /**
     * URLs per course sitemap. The protocol allows 50,000; this leaves plenty
     * of room to grow and keeps each cached document small.
     */
    protected const CHUNK = 5000;

    /**
     * The sitemap index: the static pages sitemap plus every course chunk.
     */
    public function index(): Response
    {
        $body = Cache::remember('sitemap.index', now()->addDay(), function (): string {
            $lastmod = $this->coursesLastModified();

            $entries = '<sitemap><loc>'.e(route('sitemap.pages')).'</loc></sitemap>';

            foreach (range(1, $this->chunkCount()) as $page) {
                $entries .= '<sitemap>'
                    .'<loc>'.e(route('sitemap.courses', ['page' => $page])).'</loc>'
                    .'<lastmod>'.$lastmod.'</lastmod>'
                    .'</sitemap>';
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .$entries
                .'</sitemapindex>';
        });

        return $this->xml($body);
    }

    /**
     * The handful of static public pages.
     */
    public function pages(): Response
    {
        $body = Cache::remember('sitemap.pages', now()->addDay(), fn (): string => $this->urlset([
            ['loc' => route('home'), 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['loc' => route('docs'), 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['loc' => route('explorer'), 'changefreq' => 'weekly', 'priority' => '0.8'],
        ]));

        return $this->xml($body);
    }

    /**
     * One chunk of course detail pages.
     */
    public function courses(int $page): Response
    {
        if ($page < 1 || $page > $this->chunkCount()) {
            throw new NotFoundHttpException;
        }

        $body = Cache::remember("sitemap.courses.{$page}", now()->addDay(), function () use ($page): string {
            $urls = [];

            // Only the columns needed to build a URL — never hydrate layout_data
            // for 5,000 rows.
            Course::query()
                ->select(['id', 'club_name', 'course_name', 'updated_at'])
                ->orderBy('id')
                ->forPage($page, self::CHUNK)
                ->cursor()
                ->each(function (Course $course) use (&$urls): void {
                    $urls[] = [
                        'loc' => route('courses.show', [
                            'course' => $course->id,
                            'slug' => $course->urlSlug(),
                        ]),
                        'lastmod' => $course->updated_at?->toAtomString(),
                        'changefreq' => 'monthly',
                        'priority' => '0.6',
                    ];
                });

            return $this->urlset($urls);
        });

        return $this->xml($body);
    }

    /**
     * robots.txt.
     *
     * Served from a route rather than public/robots.txt so the Sitemap line
     * always points at the current APP_URL — a static file can't read config,
     * and a hardcoded host silently rots when the domain or scheme changes.
     */
    public function robots(): Response
    {
        $disallow = [
            '/dashboard',
            '/settings/',
            '/api/',
            '/login',
            '/register',
            '/forgot-password',
            '/reset-password',
            '/two-factor-challenge',
            '/email/verify',
            '/user/',
        ];

        $body = "User-agent: *\nAllow: /\n\n"
            .collect($disallow)->map(fn (string $path): string => "Disallow: {$path}")->implode("\n")
            ."\n\nSitemap: ".route('sitemap.index')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * How many course sitemaps the index should list.
     */
    protected function chunkCount(): int
    {
        $total = Cache::remember('sitemap.course-count', now()->addDay(), fn (): int => Course::count());

        return max(1, (int) ceil($total / self::CHUNK));
    }

    /**
     * The most recent course edit, used as the lastmod for every chunk.
     */
    protected function coursesLastModified(): string
    {
        return Cache::remember('sitemap.courses-lastmod', now()->addDay(), function (): string {
            // The query builder's max() returns the raw column value, not a date.
            $latest = Course::max('updated_at');

            return $latest ? Date::parse($latest)->toAtomString() : Date::now()->toAtomString();
        });
    }

    /**
     * Render a <urlset> document.
     *
     * @param  list<array{loc: string, lastmod?: string|null, changefreq?: string, priority?: string}>  $urls
     */
    protected function urlset(array $urls): string
    {
        $body = '';

        foreach ($urls as $url) {
            $body .= '<url><loc>'.e($url['loc']).'</loc>';

            foreach (['lastmod', 'changefreq', 'priority'] as $key) {
                if (! empty($url[$key])) {
                    $body .= "<{$key}>".e($url[$key])."</{$key}>";
                }
            }

            $body .= '</url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$body
            .'</urlset>';
    }

    protected function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
