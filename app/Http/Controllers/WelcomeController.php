<?php

namespace App\Http\Controllers;

use App\Support\BrandIcons;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Head\Facades\Head;
use Laravel\Head\Facades\Schema;

class WelcomeController extends Controller
{
    /**
     * Landing page with real, cached dataset counts for the stat band.
     */
    public function __invoke(): Response
    {
        $stats = Cache::remember('landing.stats', now()->addHour(), function (): array {
            return [
                'courses' => DB::table('courses')->count(),
                'countries' => DB::table('courses')->whereNotNull('country_id')->distinct()->count('country_id'),
                'cities' => DB::table('cities')->count(),
                'greenCenters' => DB::table('courses')->where('layout_data', 'like', '%greenCenters%')->count(),
            ];
        });

        // Google derives the site name it appends to every result title, and
        // treats WebSite structured data as the strongest signal. It must match
        // og:site_name (HeadServiceProvider) and Organization below, or results
        // get inconsistent suffixes — which is how one page ended up rendering
        // as "GCA — The Golf Courses API - GCA".
        Head::schema(Schema::organization()
            ->name('GCA')
            ->url(route('home'))
            ->logo(BrandIcons::url('icon-512.png')))
            ->schema(Schema::webSite()
                ->name('GCA')
                ->alternateName('Golf Courses API')
                ->url(route('home')));

        return Inertia::render('Welcome', [
            'stats' => $stats,
            'plans' => config('api.plans'),
        ]);
    }
}
