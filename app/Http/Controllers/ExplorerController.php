<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public /explorer showcase page. Passes the browser-safe Algolia search
 * credentials + index names so the autocomplete can query Algolia directly.
 * When Algolia isn't configured, the page renders a "not configured" state.
 */
class ExplorerController extends Controller
{
    public function __invoke(): Response
    {
        $appId = config('services.algolia.app_id');
        $searchKey = config('services.algolia.search_key');
        $mapsKey = config('services.google.places_key');

        return Inertia::render('Explorer', [
            'algolia' => [
                'app_id' => $appId,
                'search_key' => $searchKey,
                'configured' => filled($appId) && filled($searchKey),
                'indices' => [
                    'courses' => (new Course)->searchableAs(),
                    'cities' => (new City)->searchableAs(),
                    'states' => (new State)->searchableAs(),
                    'countries' => (new Country)->searchableAs(),
                ],
            ],
            'maps' => [
                'key' => $mapsKey,
                'configured' => filled($mapsKey),
            ],
            'baseUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }
}
