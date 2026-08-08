<?php

namespace App\Http\Controllers;

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

        Head::schema(Schema::organization()
            ->name('GCA')
            ->url(route('home'))
            ->logo(asset('apple-touch-icon.png')))
            ->schema(Schema::webSite()
                ->name('GCA — The Golf Courses API')
                ->url(route('home')));

        return Inertia::render('Welcome', [
            'stats' => $stats,
            'plans' => config('api.plans'),
        ]);
    }
}
