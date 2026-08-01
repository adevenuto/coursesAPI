<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

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

        return Inertia::render('Welcome', ['stats' => $stats]);
    }
}
