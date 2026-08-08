<?php

namespace App\Http\Controllers;

use App\Support\BrandIcons;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest.
 *
 * Served from a route rather than public/site.webmanifest for the same reasons
 * as robots.txt: a static file can't carry the versioned icon URLs, can't read
 * the app's theme colour, and was being served as text/plain because nothing
 * maps the .webmanifest extension.
 *
 * There must be no public/site.webmanifest — Apache serves an existing file
 * ahead of the route.
 */
class WebManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json([
                'name' => 'GCA — Golf Courses API',
                'short_name' => config('app.name', 'GCA'),
                'description' => 'Thousands of golf courses worldwide — locations, scorecards and per-hole green-center GPS — behind one clean, fast REST API.',
                'start_url' => '/',
                'scope' => '/',
                'display' => 'standalone',
                'background_color' => BrandIcons::THEME_COLOR,
                'theme_color' => BrandIcons::THEME_COLOR,
                'icons' => BrandIcons::manifestIcons(),
            ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            // json() would otherwise send application/json.
            ->header('Content-Type', 'application/manifest+json; charset=UTF-8');
    }
}
