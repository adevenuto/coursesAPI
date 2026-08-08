<?php

namespace App\Providers;

use App\Head\Schemas\GolfCourse;
use App\Support\BrandIcons;
use Illuminate\Support\ServiceProvider;
use Laravel\Head\Enums\ImageType;
use Laravel\Head\Enums\OgType;
use Laravel\Head\Enums\TwitterCard;
use Laravel\Head\ErrorPages;
use Laravel\Head\Facades\Head;
use Laravel\Head\Facades\Schema;
use Laravel\Head\HeadBuilder;

/**
 * Document <head> metadata: site-wide defaults, static Inertia tags, and error
 * page metadata. Per-page values are set on routes via withHead() or at runtime
 * in controllers via the Head facade; both override what is registered here.
 */
class HeadServiceProvider extends ServiceProvider
{
    /**
     * The site-wide social share image (1200x630).
     */
    protected const OG_IMAGE = 'og-image.png';

    public function boot(): void
    {
        Schema::register(GolfCourse::class);

        $this->registerDefaults();
        $this->registerInertiaGlobals();
        $this->registerErrorPages();
    }

    /**
     * Lowest-priority layer. Every page inherits these unless it sets its own.
     *
     * The suffix applies to titles set by higher layers, so `title('Docs')`
     * renders "Docs — GCA". Pass `exact: true` to opt out.
     */
    protected function registerDefaults(): void
    {
        Head::defaults(fn (HeadBuilder $head) => $head
            ->title('GCA', suffix: ' — GCA')
            ->description('Thousands of golf courses worldwide — locations, scorecards and per-hole green-center GPS — behind one clean, fast REST API. Get a free key.')
            ->canonical()
            ->og(type: OgType::Website, siteName: 'GCA')
            ->ogImage(asset(self::OG_IMAGE), alt: 'GCA — The Golf Courses API', width: 1200, height: 630)
            ->twitter(card: TwitterCard::SummaryWithLargeImage)
            ->searchableByRobots()
            ->preconnect('https://fonts.googleapis.com')
            ->preconnect('https://fonts.gstatic.com', crossorigin: true));
    }

    /**
     * Tags that are identical for every page of a session. These render once in
     * the initial response without Inertia ownership attributes and are never
     * updated on subsequent visits, so they must never be page-specific.
     */
    protected function registerInertiaGlobals(): void
    {
        Head::inertiaGlobals(fn (HeadBuilder $head) => $head
            ->viewport('width=device-width, initial-scale=1')
            ->themeColor(BrandIcons::THEME_COLOR)
            ->icon(BrandIcons::url('favicon.svg'), type: ImageType::Svg)
            ->icon(BrandIcons::url('favicon.ico'), sizes: '48x48 32x32 16x16')
            ->appleTouchIcon(BrandIcons::url('apple-touch-icon.png'), sizes: '180x180')
            // Not route('manifest'): inertiaGlobals() runs its callback during
            // boot(), before the route table exists. The path is fixed anyway,
            // and going through url() versions the manifest alongside the icons.
            ->manifest(BrandIcons::url('site.webmanifest')));
    }

    /**
     * Error metadata outranks every other layer. `noindex, follow` keeps error
     * pages out of the index while still letting crawlers follow their links.
     */
    protected function registerErrorPages(): void
    {
        Head::errors(function (ErrorPages $errors) {
            $errors->defaults(robots: 'noindex, follow');

            $errors->status(403, title: 'Access Denied', description: 'You do not have permission to view this page.');
            $errors->status(404, title: 'Page Not Found', description: 'The page you are looking for could not be found.');
            $errors->status(419, title: 'Page Expired', description: 'Your session expired. Please refresh and try again.');
            $errors->status(429, title: 'Too Many Requests', description: 'You have made too many requests. Please slow down and try again shortly.');
            $errors->status(500, title: 'Server Error', description: 'Something went wrong on our end.');
        });
    }
}
